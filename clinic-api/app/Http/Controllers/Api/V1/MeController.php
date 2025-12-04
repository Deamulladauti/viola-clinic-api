<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
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

        // Normalize email for safe comparison
        $email = strtolower(trim((string) $user->email));

        $status   = $request->query('status');      // optional
        $upcoming = $request->query('upcoming');    // "1" or "0" or null

        $q = Appointment::with(['service','staff'])
            ->whereRaw('LOWER(customer_email) = ?', [$email]);

        if ($status) {
            $q->where('status', $status);
        }

        // Upcoming / past filter
        if (!is_null($upcoming)) {
            $now = Carbon::now();

            $q->where(function ($qq) use ($upcoming, $now) {
                if (filter_var($upcoming, FILTER_VALIDATE_BOOLEAN)) {
                    // upcoming: (date > today) OR (date == today AND starts_at >= now time)
                    $qq->whereDate('date', '>', $now->toDateString())
                       ->orWhere(function ($q2) use ($now) {
                           $q2->whereDate('date', $now->toDateString())
                              ->where('starts_at', '>=', $now->format('H:i:s'));
                       });
                } else {
                    // past: (date < today) OR (date == today AND starts_at < now time)
                    $qq->whereDate('date', '<', $now->toDateString())
                       ->orWhere(function ($q2) use ($now) {
                           $q2->whereDate('date', $now->toDateString())
                              ->where('starts_at', '<', $now->format('H:i:s'));
                       });
                }
            });
        }

        $appointments = $q->orderByDesc('date')
            ->orderByDesc('starts_at')
            ->get()
            ->map(function (Appointment $a) {
                // Normalize date
                $date = $a->date instanceof Carbon
                    ? $a->date->toDateString()
                    : Carbon::parse($a->date)->toDateString();

                // Normalize starts_at to HH:MM:SS safely
                $startsAt = $a->starts_at;
                if ($startsAt) {
                    // "14:35" → "14:35:00"
                    if (strlen($startsAt) === 5) {
                        $startsAt .= ':00';
                    }
                } else {
                    // Fallback if somehow null
                    $startsAt = '00:00:00';
                }

                // Safe parsing (this was causing your error before)
                $start = Carbon::parse("{$date} {$startsAt}");

                $durationMinutes = (int) ($a->duration_minutes ?? 0);
                $end = (clone $start)->addMinutes($durationMinutes);

                return [
                    'id'         => $a->id,
                    'reference'  => $a->reference_code,
                    'service'    => [
                        'id'   => $a->service?->id,
                        'name' => $a->service?->name,
                        'slug' => $a->service?->slug,
                    ],
                    'staff'      => $a->staff ? [
                        'id'   => $a->staff->id,
                        'name' => $a->staff->name,
                    ] : null,
                    'date'       => $date,
                    'time'       => $a->starts_at,               // original string
                    'end_time'   => $end->format('H:i:s'),
                    'duration_minutes' => $durationMinutes,
                    'price'      => (float) ($a->price ?? 0),
                    'status'     => $a->status,
                    'notes'      => $a->notes,
                    'display'    => [
                        'date_time' => $start->format('Y-m-d H:i'),
                        'range'     => $start->format('H:i') . '–' . $end->format('H:i'),
                    ],
                ];
            });

        return response()->json(['appointments' => $appointments]);
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
