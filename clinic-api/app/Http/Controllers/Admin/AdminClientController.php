<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Http\Request;

class AdminClientController extends Controller
{
    public function lookupByPhone(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $raw = trim($validated['phone']);
        $digits = preg_replace('/\D+/', '', $raw);

        $query = User::query()
            ->where(function ($q) use ($raw, $digits) {
                $q->where('phone', $raw);

                if ($digits !== '') {
                    $q->orWhere('phone', $digits)
                        ->orWhere('phone', '+' . $digits);
                }
            })
            ->whereHas('roles', function ($q) {
                $q->where('name', 'client');
            });

        $user = $query->first();

        if (! $user) {
            return response()->json([
                'found' => false,
                'message' => 'Client not found',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'client' => $this->formatClient($user),
        ]);
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 20);
        $digits = preg_replace('/\D+/', '', $q);

        $query = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'client');
            });

        if ($q !== '') {
            $query->where(function ($sub) use ($q, $digits) {
                $sub->where('name', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%')
                    ->orWhere('phone', 'like', '%' . $q . '%');

                if ($digits !== '') {
                    $sub->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%' . $digits . '%']);
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

    public function show($id)
    {
        $user = User::query()
            ->whereKey($id)
            ->whereHas('roles', function ($q) {
                $q->where('name', 'client');
            })
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Client not found',
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