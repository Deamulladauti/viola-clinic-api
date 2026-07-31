<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PackageUsageService
{
    public function recordSessionForAppointment(
        Appointment $appointment,
        ?int $actorUserId,
        ?string $note = null,
        string $source = PackageLog::SOURCE_AUTOMATIC,
        bool $enforceInterval = true,
    ): PackageLog {
        return DB::transaction(function () use ($appointment, $actorUserId, $note, $source, $enforceInterval) {
            $appointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            if (! $appointment->service_package_id) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'A package session appointment must be linked to a specific package.',
                ]);
            }

            $existing = PackageLog::query()
                ->where('service_package_id', $appointment->service_package_id)
                ->where('appointment_id', $appointment->id)
                ->whereNull('voided_at')
                ->where(function ($query) {
                    $query->where('usage_type', Service::USAGE_SESSION)
                        ->orWhere('used_sessions', '>', 0);
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $package = ServicePackage::query()
                ->lockForUpdate()
                ->findOrFail($appointment->service_package_id);

            try {
                $package->assertMatchesAppointment($appointment);
                $package->assertUsableOn(
                    $appointment->date,
                    allowBeforeStart: $source === PackageLog::SOURCE_IMPORTED,
                );
            } catch (\LogicException $exception) {
                throw ValidationException::withMessages([
                    'service_package_id' => $exception->getMessage(),
                ]);
            }

            if (! $package->isSessionsType()) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'Quantity/minute packages cannot be consumed by completing an appointment.',
                ]);
            }

            if ($package->deductionMethod() !== Service::DEDUCTION_AUTOMATIC) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'This package is not configured for automatic appointment completion deduction.',
                ]);
            }

            if ((int) $package->remaining_sessions <= 0) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'This package has no sessions remaining.',
                ]);
            }

            if (! $appointment->staff_id) {
                throw ValidationException::withMessages([
                    'staff_id' => 'A staff member is required before a package session can be completed.',
                ]);
            }

            $this->applySameStaffPolicy($package, $appointment);

            $occurredOn = $appointment->date instanceof Carbon
                ? $appointment->date->toDateString()
                : Carbon::parse($appointment->date)->toDateString();

            if ($enforceInterval) {
                $this->assertMinimumInterval($package, $occurredOn);
            }

            $package->remaining_sessions = (int) $package->remaining_sessions - 1;
            if ($package->remaining_sessions <= 0) {
                $package->status = ServicePackage::STATUS_EXHAUSTED;
            }
            $package->save();

            $log = PackageLog::create([
                'service_package_id' => $package->id,
                'staff_id' => $appointment->staff_id,
                'appointment_id' => $appointment->id,
                'active_appointment_id' => $appointment->id,
                'appointment_ref' => $appointment->reference_code,
                'usage_type' => Service::USAGE_SESSION,
                'quantity' => 1,
                'session_number' => null,
                'used_sessions' => 1,
                'used_minutes' => 0,
                'used_at' => Carbon::parse($occurredOn)->startOfDay(),
                'occurred_on' => $occurredOn,
                'source' => $source,
                'created_by_id' => $actorUserId,
                'note' => $note,
            ]);

            $this->renumberSessionLogs($package->id);

            return $log->refresh();
        });
    }

    public function recordManualQuantityUsage(
        ServicePackage $package,
        int $quantity,
        string $occurredOn,
        ?int $staffId,
        ?int $actorUserId,
        ?string $note = null,
        string $source = PackageLog::SOURCE_MANUAL,
    ): PackageLog {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Usage amount must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($package, $quantity, $occurredOn, $staffId, $actorUserId, $note, $source) {
            $package = ServicePackage::query()->lockForUpdate()->findOrFail($package->id);
            $date = Carbon::parse($occurredOn)->toDateString();

            try {
                $package->assertUsableOn($date);
            } catch (\LogicException $exception) {
                throw ValidationException::withMessages([
                    'package' => $exception->getMessage(),
                ]);
            }

            if (! $package->isMinutesType()) {
                throw ValidationException::withMessages([
                    'package' => 'Only quantity/minute packages can be used manually.',
                ]);
            }

            if ($package->deductionMethod() !== Service::DEDUCTION_MANUAL) {
                throw ValidationException::withMessages([
                    'package' => 'This package is not configured for manual usage.',
                ]);
            }

            if ($quantity > (int) $package->remaining_minutes) {
                throw ValidationException::withMessages([
                    'amount' => 'Usage cannot exceed the remaining package minutes.',
                ]);
            }

            $package->remaining_minutes = (int) $package->remaining_minutes - $quantity;
            if ($package->remaining_minutes <= 0) {
                $package->status = ServicePackage::STATUS_EXHAUSTED;
            }
            $package->save();

            return PackageLog::create([
                'service_package_id' => $package->id,
                'staff_id' => $staffId,
                'appointment_id' => null,
                'active_appointment_id' => null,
                'appointment_ref' => null,
                'usage_type' => Service::USAGE_MINUTES,
                'quantity' => $quantity,
                'session_number' => null,
                'used_sessions' => 0,
                'used_minutes' => $quantity,
                'used_at' => Carbon::parse($date)->startOfDay(),
                'occurred_on' => $date,
                'source' => $source,
                'created_by_id' => $actorUserId,
                'note' => $note,
            ]);
        });
    }

    public function voidAppointmentUsage(
        Appointment $appointment,
        ?int $actorUserId,
        string $reason,
    ): ?PackageLog {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when reversing completed package usage.',
            ]);
        }

        return DB::transaction(function () use ($appointment, $actorUserId, $reason) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            $log = PackageLog::query()
                ->where('appointment_id', $appointment->id)
                ->whereNull('voided_at')
                ->where(function ($query) {
                    $query->where('quantity', '>', 0)
                        ->orWhere('used_sessions', '>', 0)
                        ->orWhere('used_minutes', '>', 0);
                })
                ->lockForUpdate()
                ->first();

            if (! $log) {
                return null;
            }

            $package = ServicePackage::query()->lockForUpdate()->findOrFail($log->service_package_id);
            $quantity = (int) ($log->quantity ?: ($log->used_sessions ?: $log->used_minutes));
            $usageType = $log->usage_type
                ?: ((int) $log->used_minutes > 0 ? Service::USAGE_MINUTES : Service::USAGE_SESSION);

            if ($usageType === Service::USAGE_SESSION) {
                $package->remaining_sessions = (int) $package->remaining_sessions + $quantity;
            } elseif ($usageType === Service::USAGE_MINUTES) {
                $package->remaining_minutes = (int) $package->remaining_minutes + $quantity;
            }

            if ($package->status === ServicePackage::STATUS_EXHAUSTED) {
                $package->status = ServicePackage::STATUS_ACTIVE;
            }
            $package->save();

            $log->forceFill([
                'active_appointment_id' => null,
                'voided_at' => now(),
                'voided_by_id' => $actorUserId,
                'void_reason' => $reason,
            ])->save();

            if ($usageType === Service::USAGE_SESSION) {
                $this->renumberSessionLogs($package->id);
            }

            return $log->refresh();
        });
    }

    private function applySameStaffPolicy(ServicePackage $package, Appointment $appointment): void
    {
        if ($package->staffPolicy() !== Service::STAFF_SAME) {
            return;
        }

        if (! $package->assigned_staff_id) {
            $package->assigned_staff_id = $appointment->staff_id;
            $package->save();

            return;
        }

        if ((int) $package->assigned_staff_id !== (int) $appointment->staff_id) {
            throw ValidationException::withMessages([
                'staff_id' => 'This package is locked to its assigned staff member.',
            ]);
        }
    }

    private function assertMinimumInterval(ServicePackage $package, string $occurredOn): void
    {
        $minimumDays = (int) ($package->snapshot_minimum_interval_days ?? 0);
        if ($minimumDays <= 0) {
            return;
        }

        $date = Carbon::parse($occurredOn)->startOfDay();

        $previous = PackageLog::query()
            ->where('service_package_id', $package->id)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->where('usage_type', Service::USAGE_SESSION)
                    ->orWhere('used_sessions', '>', 0);
            })
            ->whereDate('occurred_on', '<=', $date->toDateString())
            ->orderByDesc('occurred_on')
            ->first();

        if ($previous?->occurred_on) {
            $nextAllowed = $previous->occurred_on->copy()->addDays($minimumDays);
            if ($date->lt($nextAllowed)) {
                throw ValidationException::withMessages([
                    'date' => "The next package session is allowed from {$nextAllowed->toDateString()}.",
                ]);
            }
        }

        $next = PackageLog::query()
            ->where('service_package_id', $package->id)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->where('usage_type', Service::USAGE_SESSION)
                    ->orWhere('used_sessions', '>', 0);
            })
            ->whereDate('occurred_on', '>', $date->toDateString())
            ->orderBy('occurred_on')
            ->first();

        if ($next?->occurred_on && $date->copy()->addDays($minimumDays)->gt($next->occurred_on)) {
            throw ValidationException::withMessages([
                'date' => 'The selected date conflicts with the minimum interval before the following session.',
            ]);
        }
    }

    private function renumberSessionLogs(int $packageId): void
    {
        $logs = PackageLog::query()
            ->where('service_package_id', $packageId)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->where('usage_type', Service::USAGE_SESSION)
                    ->orWhere('used_sessions', '>', 0);
            })
            ->orderBy('occurred_on')
            ->orderBy('used_at')
            ->orderBy('id')
            ->get();

        foreach ($logs as $index => $log) {
            $number = $index + 1;
            if ((int) $log->session_number !== $number) {
                $log->session_number = $number;
                $log->save();
            }
        }
    }
}
