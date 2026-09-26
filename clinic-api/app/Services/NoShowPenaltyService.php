<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\NoShowPolicySetting;
use App\Models\PackageLog;
use App\Models\ServicePackage;
use Illuminate\Support\Facades\DB;

/** Called inside the same transaction that changes the appointment to no_show. */
class NoShowPenaltyService
{
    public function applyFor(Appointment $appointment, ?int $actorUserId): ?PackageLog
    {
        if ($appointment->status !== Appointment::STATUS_NO_SHOW || ! $appointment->service_package_id) {
            return null;
        }

        // Lock package first: concurrent no-shows for the same package must serialize.
        $package = ServicePackage::query()->lockForUpdate()->findOrFail($appointment->service_package_id);
        if (! $package->isSessionsType()) {
            return null;
        }

        $policy = NoShowPolicySetting::current();
        if (! $policy->is_enabled) {
            return null;
        }

        $existing = PackageLog::query()
            ->where('penalty_trigger_appointment_id', $appointment->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $threshold = max(1, (int) $policy->misses_before_penalty);
        $missCount = Appointment::query()
            ->where('service_package_id', $package->id)
            ->where('status', Appointment::STATUS_NO_SHOW)
            ->count();
        if ($missCount === 0 || $missCount % $threshold !== 0) {
            return null;
        }

        $quantity = (int) $policy->sessions_to_deduct;
        // Do not create fictitious deductions or allow negative balances.
        if ($quantity <= 0 || (int) $package->remaining_sessions < $quantity) {
            return null;
        }

        $package->remaining_sessions = (int) $package->remaining_sessions - $quantity;
        if ($package->remaining_sessions === 0) {
            $package->status = ServicePackage::STATUS_EXHAUSTED;
        }
        $package->save();

        $date = $appointment->date instanceof \DateTimeInterface
            ? $appointment->date->format('Y-m-d')
            : \Illuminate\Support\Carbon::parse($appointment->date)->toDateString();

        return PackageLog::create([
            'service_package_id' => $package->id,
            'staff_id' => $appointment->staff_id,
            'appointment_id' => $appointment->id,
            // Leave active_appointment_id NULL: it is reserved for completed-session usage.
            'active_appointment_id' => null,
            'appointment_ref' => $appointment->reference_code,
            'usage_type' => 'session',
            'quantity' => $quantity,
            'used_sessions' => $quantity,
            'used_minutes' => 0,
            'used_at' => now(),
            'occurred_on' => $date,
            'source' => PackageLog::SOURCE_NO_SHOW_PENALTY,
            'created_by_id' => $actorUserId,
            'penalty_trigger_appointment_id' => $appointment->id,
            'penalty_policy_snapshot' => [
                'policy_id' => $policy->id,
                'misses_before_penalty' => $threshold,
                'sessions_to_deduct' => $quantity,
                'qualifying_miss_number' => $missCount,
                'trigger' => 'no_show',
                'trigger_appointment_id' => $appointment->id,
            ],
            'note' => "No-show penalty: {$missCount} misses / {$threshold} threshold; {$quantity} session(s) deducted. Appointment #{$appointment->id}.",
        ]);
    }
}
