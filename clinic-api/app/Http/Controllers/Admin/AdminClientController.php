<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminClientController extends Controller
{
    /**
     * Find a client by phone number.
     */
    public function lookupByPhone(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
        ]);

        $raw = trim($validated['phone']);
        $digits = preg_replace('/\D+/', '', $raw);

        $query = User::query()
            ->whereHas('roles', function ($query) {
                $query->where('name', 'client');
            })
            ->where(function ($query) use ($raw, $digits) {
                $query->where('phone', $raw);

                if ($digits !== '') {
                    $query
                        ->orWhere('phone', $digits)
                        ->orWhere('phone', '+' . $digits)
                        ->orWhereRaw(
                            "REGEXP_REPLACE(phone, '[^0-9]', '') = ?",
                            [$digits]
                        );
                }
            });

        $user = $query->first();

        if (! $user) {
            return response()->json([
                'found' => false,
                'message' => 'Client not found.',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'client' => $this->formatClient($user),
        ]);
    }

    /**
     * Search clients by name, email, or phone.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 20);
        $digits = preg_replace('/\D+/', '', $search);

        $query = User::query()
            ->whereHas('roles', function ($query) {
                $query->where('name', 'client');
            });

        if ($search !== '') {
            $query->where(function ($query) use ($search, $digits) {
                $query
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');

                if ($digits !== '') {
                    $query->orWhereRaw(
                        "REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?",
                        ['%' . $digits . '%']
                    );
                }
            });
        }

        $clients = $query
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => $this->formatClient($user))
            ->values();

        return response()->json($clients);
    }

    /**
     * Create a new client from the admin area.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
        ]);

        $name = trim($validated['name']);

        $phone = isset($validated['phone'])
            ? trim($validated['phone'])
            : null;

        $email = isset($validated['email'])
            ? mb_strtolower(trim($validated['email']))
            : null;

        if ($phone === '') {
            $phone = null;
        }

        if ($email === '') {
            $email = null;
        }

        /*
         * Require at least one contact method.
         */
        if (! $phone && ! $email) {
            throw ValidationException::withMessages([
                'contact' => [
                    'Enter at least a phone number or email address.',
                ],
            ]);
        }

        /*
         * Check email across active and soft-deleted users.
         */
        if ($email) {
            $emailExists = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => [
                        'This email address is already being used.',
                    ],
                ]);
            }
        }

        /*
         * Check phone across active and soft-deleted users.
         * Formatting characters are ignored.
         */
        if ($phone) {
            $phoneDigits = preg_replace('/\D+/', '', $phone);

            if ($phoneDigits === '') {
                throw ValidationException::withMessages([
                    'phone' => [
                        'Enter a valid phone number.',
                    ],
                ]);
            }

            $phoneExists = User::withTrashed()
                ->where(function ($query) use ($phone, $phoneDigits) {
                    $query
                        ->where('phone', $phone)
                        ->orWhereRaw(
                            "REGEXP_REPLACE(phone, '[^0-9]', '') = ?",
                            [$phoneDigits]
                        );
                })
                ->exists();

            if ($phoneExists) {
                throw ValidationException::withMessages([
                    'phone' => [
                        'This phone number is already being used.',
                    ],
                ]);
            }
        }

        $client = DB::transaction(function () use ($name, $phone, $email) {
            $client = User::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'password' => null,
            ]);

            $client->assignRole('client');

            return $client;
        });

        return response()->json([
            'message' => 'Client created successfully.',
            'data' => $this->formatClient($client),
        ], 201);
    }

    /**
     * Show one client's details.
     */
    public function show(int $id)
    {
        $user = User::query()
            ->whereKey($id)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'client');
            })
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Client not found.',
            ], 404);
        }

        $activePackagesCount = ServicePackage::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        $totalPackagesCount = ServicePackage::query()
            ->where('user_id', $user->id)
            ->count();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'active_packages_count' => $activePackagesCount,
            'total_packages_count' => $totalPackagesCount,
            'created_at' => $user->created_at,
        ]);
    }

    /**
     * Standard client response used by search, lookup, and creation.
     */
    protected function formatClient(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }
}