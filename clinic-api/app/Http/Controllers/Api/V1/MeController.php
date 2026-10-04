<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Storage;

class MeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => [
                    'line1'        => $user->address_line1,
                    'line2'        => $user->address_line2,
                    'city'         => $user->city,
                    'country_code' => $user->country_code,
                ],
                'preferred_language'    => $user->preferred_language,
                'marketing_opt_in'      => (bool) $user->marketing_opt_in,
                'notifications_enabled' => (bool) $user->notifications_enabled,
                'avatar_url'            => $user->avatar_url,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:255'],
            'phone'                 => ['sometimes', 'nullable', 'string', 'max:30'],
            'address_line1'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'                  => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code'          => ['sometimes', 'nullable', 'string', 'size:2'],
            'preferred_language'    => ['sometimes', 'string', 'in:en,sq,mk'],
            'marketing_opt_in'      => ['sometimes', 'boolean'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ]);

        // Just in case email sneaks into the payload one day
        unset($data['email']);

        $user->fill($data)->save();

        return response()->json([
            'message' => 'Profile updated',
            'user'    => $this->freshUserPayload($user->refresh()),
        ]);
    }

    public function updateAvatar(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->avatar_path = $path;
        $user->save();

        return response()->json([
            'message'    => 'Avatar updated',
            'avatar_url' => $user->avatar_url,
        ]);
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        return response()->json(['message' => 'Avatar removed']);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }

    private function freshUserPayload($user): array
    {
        return [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'phone'                 => $user->phone,
            'address'               => [
                'line1'        => $user->address_line1,
                'line2'        => $user->address_line2,
                'city'         => $user->city,
                'country_code' => $user->country_code,
            ],
            'preferred_language'    => $user->preferred_language,
            'marketing_opt_in'      => (bool) $user->marketing_opt_in,
            'notifications_enabled' => (bool) $user->notifications_enabled,
            'avatar_url'            => $user->avatar_url,
        ];
    }

    public function appointments(Request $request)
    {
        $user = $request->user();
        $email = strtolower(trim((string) $user->email));

        $status   = $request->query('status');
        $upcoming = $request->query('upcoming');

        $q = Appointment::with(['service', 'staff', 'bookingGroup'])
            ->where(function ($query) use ($user, $email) {
                // Current bookings should be linked by user_id. Keep the email
                // fallback for older bookings created before account linking.
                $query->where('user_id', $user->id);
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            });

        if ($status) {
            $q->where('status', $status);
        }

        if (!is_null($upcoming)) {
            $now = Carbon::now(config('clinic.timezone', config('app.timezone')));
            $q->where(function ($qq) use ($upcoming, $now) {
                if (filter_var($upcoming, FILTER_VALIDATE_BOOLEAN)) {
                    $qq->whereDate('date', '>', $now->toDateString())
                       ->orWhere(function ($q2) use ($now) {
                           $q2->whereDate('date', $now->toDateString())
                              ->where('starts_at', '>=', $now->format('H:i:s'));
                       });
                } else {
                    $qq->whereDate('date', '<', $now->toDateString())
                       ->orWhere(function ($q2) use ($now) {
                           $q2->whereDate('date', $now->toDateString())
                              ->where('starts_at', '<', $now->format('H:i:s'));
                       });
                }
            });
        }

        $appointments = $q->orderByDesc('date')->orderByDesc('starts_at')->get();
        $packageProgress = $this->buildClientPackageSessionProgress($appointments);

        $payload = $appointments->map(function (Appointment $a) use ($packageProgress) {
            $date = $a->date instanceof Carbon
                ? $a->date->toDateString()
                : Carbon::parse($a->date)->toDateString();

            $startsAt = $a->starts_at ?: '00:00:00';
            if (strlen($startsAt) === 5) {
                $startsAt .= ':00';
            }

            $start = Carbon::parse("{$date} {$startsAt}");
            $durationMinutes = (int) ($a->duration_minutes ?? 0);
            $end = (clone $start)->addMinutes($durationMinutes);
            $finalPrice = (float) ($a->sale_final_price ?? $a->price ?? 0);
            $originalPrice = (float) ($a->sale_original_price ?? $finalPrice);

            return [
                'id' => $a->id,
                'reference' => $a->reference_code,
                'booking_group_id' => $a->booking_group_id ? (int) $a->booking_group_id : null,
                'service' => [
                    'id' => $a->service?->id,
                    'name' => $a->service?->name,
                    'slug' => $a->service?->slug,
                ],
                'staff' => $a->staff ? [
                    'id' => $a->staff->id,
                    'name' => $a->staff->name,
                ] : null,
                'date' => $date,
                'time' => $a->starts_at,
                'end_time' => $end->format('H:i:s'),
                'duration_minutes' => $durationMinutes,
                'price' => $finalPrice,
                'pricing' => [
                    'original_price' => $originalPrice,
                    'final_price' => $finalPrice,
                    'discount_amount' => (float) ($a->sale_discount_amount ?? 0),
                    'offer_name' => $a->sale_offer_name,
                    'has_discount' => $originalPrice > $finalPrice,
                ],
                'package' => $packageProgress[(int) $a->id] ?? null,
                'status' => $a->status,
                'notes' => $a->notes,
                'display' => [
                    'date_time' => $start->format('Y-m-d H:i'),
                    'range' => $start->format('H:i') . '–' . $end->format('H:i'),
                ],
            ];
        });

        return response()->json(['appointments' => $payload]);
    }

    /**
     * Client-facing package session progress. Mirrors the Admin calendar rule:
     * completed visits use the package ledger; future active bookings are numbered
     * after consumed sessions in chronological order across the whole package.
     */
    private function buildClientPackageSessionProgress($appointments): array
    {
        $packageIds = $appointments->pluck('service_package_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($packageIds->isEmpty()) {
            return [];
        }

        $packages = ServicePackage::query()->whereIn('id', $packageIds)->get([
            'id', 'snapshot_total_sessions', 'remaining_sessions', 'snapshot_usage_type',
        ])->keyBy('id');

        $logs = PackageLog::query()
            ->whereIn('service_package_id', $packageIds)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->where('usage_type', Service::USAGE_SESSION)->orWhere('used_sessions', '>', 0);
            })
            ->orderBy('service_package_id')->orderBy('occurred_on')->orderBy('used_at')->orderBy('id')
            ->get(['id', 'service_package_id', 'appointment_id', 'session_number']);

        $logsByPackage = $logs->groupBy('service_package_id');
        $completed = [];
        $usedCounts = [];
        foreach ($packageIds as $packageId) {
            $packageLogs = $logsByPackage->get($packageId, collect())->values();
            $usedCounts[$packageId] = $packageLogs->count();
            foreach ($packageLogs as $index => $log) {
                if ($log->appointment_id) {
                    $completed[(int) $log->appointment_id] = (int) ($log->session_number ?: ($index + 1));
                }
            }
        }

        $today = Carbon::today(config('clinic.timezone', config('app.timezone')))->toDateString();
        $future = Appointment::query()
            ->whereIn('service_package_id', $packageIds)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereDate('date', '>=', $today)
            ->orderBy('service_package_id')->orderBy('date')->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'service_package_id']);

        $futureNumbers = [];
        foreach ($future->groupBy('service_package_id') as $packageId => $items) {
            $used = (int) ($usedCounts[(int) $packageId] ?? 0);
            foreach ($items->values() as $index => $appointment) {
                $futureNumbers[(int) $appointment->id] = $used + $index + 1;
            }
        }

        $result = [];
        foreach ($appointments as $appointment) {
            if (!$appointment->service_package_id) continue;
            $packageId = (int) $appointment->service_package_id;
            $package = $packages->get($packageId);
            if (!$package) continue;

            $used = (int) ($usedCounts[$packageId] ?? 0);
            $total = $package->snapshot_total_sessions !== null
                ? (int) $package->snapshot_total_sessions
                : ($package->remaining_sessions !== null ? $used + (int) $package->remaining_sessions : null);
            if (!$total) continue;

            $number = $appointment->status === Appointment::STATUS_COMPLETED
                ? ($completed[(int) $appointment->id] ?? null)
                : ($futureNumbers[(int) $appointment->id] ?? null);
            if (!$number) continue;

            $result[(int) $appointment->id] = [
                'package_id' => $packageId,
                'session_number' => (int) $number,
                'total_sessions' => (int) $total,
                'remaining_sessions' => $package->remaining_sessions !== null ? (int) $package->remaining_sessions : null,
            ];
        }

        return $result;
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Optional: delete all personal access tokens (logout everywhere)
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        // Soft delete the user
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }
}
