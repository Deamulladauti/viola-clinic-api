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
         * Require at least one contact method for the current flow.
         * Task 26 will later relax this for legacy/no-login clients.
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
        $user = $this->findClient($id);

        if (! $user) {
            return response()->json([
                'message' => 'Client not found.',
            ], 404);
        }

        return response()->json($this->clientDetailsPayload($user));
    }

    /**
     * Update a clinic client from the Admin area.
     *
     * The existing users table stores the display name in a single `name`
     * column. The Admin UI edits first/last name separately for clarity and
     * this endpoint safely recombines them so the rest of the app remains
     * backward-compatible.
     */
    public function update(Request $request, int $id)
    {
        $client = $this->findClient($id);

        if (! $client) {
            return response()->json([
                'message' => 'Client not found.',
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:135'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'preferred_language' => ['nullable', 'string', 'in:en,sq,mk'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ]);

        $firstName = trim($validated['first_name']);
        $lastName = trim((string) ($validated['last_name'] ?? ''));

        if ($firstName === '') {
            throw ValidationException::withMessages([
                'first_name' => ['Enter the client\'s first name.'],
            ]);
        }

        $phone = $this->nullableTrimmed($validated['phone'] ?? null);
        $email = $this->nullableTrimmed($validated['email'] ?? null);
        $email = $email ? mb_strtolower($email) : null;

        // Keep the current Task 6 rule aligned with client creation.
        // This is intentionally isolated so Task 26 can later permit both
        // fields to be null for legacy/no-login clinic records.
        if (! $phone && ! $email) {
            throw ValidationException::withMessages([
                'contact' => ['Enter at least a phone number or email address.'],
            ]);
        }

        if ($email) {
            $emailExists = User::withTrashed()
                ->where('id', '<>', $client->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already being used.'],
                ]);
            }
        }

        if ($phone) {
            $phoneDigits = preg_replace('/\D+/', '', $phone);

            if ($phoneDigits === '') {
                throw ValidationException::withMessages([
                    'phone' => ['Enter a valid phone number.'],
                ]);
            }

            $phoneExists = User::withTrashed()
                ->where('id', '<>', $client->id)
                ->whereNotNull('phone')
                ->get(['id', 'phone'])
                ->contains(function (User $other) use ($phoneDigits) {
                    return preg_replace('/\D+/', '', (string) $other->phone) === $phoneDigits;
                });

            if ($phoneExists) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number is already being used.'],
                ]);
            }
        }

        $client->fill([
            'name' => trim($firstName . ' ' . $lastName),
            'phone' => $phone,
            'email' => $email,
            'address_line1' => $this->nullableTrimmed($validated['address_line1'] ?? null),
            'address_line2' => $this->nullableTrimmed($validated['address_line2'] ?? null),
            'city' => $this->nullableTrimmed($validated['city'] ?? null),
            'country_code' => ($country = $this->nullableTrimmed($validated['country_code'] ?? null))
                ? mb_strtoupper($country)
                : null,
            'preferred_language' => $validated['preferred_language'] ?? ($client->preferred_language ?: 'en'),
            'marketing_opt_in' => array_key_exists('marketing_opt_in', $validated)
                ? (bool) $validated['marketing_opt_in']
                : (bool) $client->marketing_opt_in,
        ])->save();

        return response()->json([
            'message' => 'Client updated successfully.',
            'data' => $this->clientDetailsPayload($client->refresh()),
        ]);
    }

    /**
     * Standard client response used by search, lookup, and creation.
     */
    protected function formatClient(User $user): array
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }

    protected function clientDetailsPayload(User $user): array
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        $activePackagesCount = ServicePackage::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        $totalPackagesCount = ServicePackage::query()
            ->where('user_id', $user->id)
            ->count();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'phone' => $user->phone,
            'address_line1' => $user->address_line1,
            'address_line2' => $user->address_line2,
            'city' => $user->city,
            'country_code' => $user->country_code,
            'preferred_language' => $user->preferred_language ?: 'en',
            'marketing_opt_in' => (bool) $user->marketing_opt_in,
            'active_packages_count' => $activePackagesCount,
            'total_packages_count' => $totalPackagesCount,
            'created_at' => $user->created_at,
        ];
    }

    protected function findClient(int $id): ?User
    {
        return User::query()
            ->whereKey($id)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'client');
            })
            ->first();
    }

    /**
     * Split the existing display-name column into Admin form fields without
     * forcing a schema migration or breaking other screens that read `name`.
     */
    protected function splitName(?string $name): array
    {
        $clean = trim((string) $name);

        if ($clean === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $clean, 2);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }

    protected function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }
}
