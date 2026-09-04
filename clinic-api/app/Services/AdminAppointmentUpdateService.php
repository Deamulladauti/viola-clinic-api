<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\PackagePayment;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminAppointmentUpdateService
{
    public function __construct(private readonly AppointmentBookingValidator $bookingValidator)
    {
    }

    /**
     * Safely update the editable/operational fields of a scheduled appointment.
     *
     * Completed visits are deliberately excluded. They must first go through
     * AppointmentCompletionService::reverseCompletion(), which voids package
     * usage and records the correction reason before the appointment can be
     * edited and completed again.
     *
     * @return array{appointment: Appointment, warnings: array<int, string>, next_allowed_date: ?string}
     */
    public function update(Appointment $appointment, array $data, User $admin): array
    {
        return DB::transaction(function () use ($appointment, $data, $admin) {
            $appointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            if ($appointment->status === Appointment::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'appointment' => 'Completed visits cannot be edited directly. Use Correct completed visit first so package usage is restored and audited.',
                ]);
            }

            if (!in_array($appointment->status, [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
            ], true)) {
                throw ValidationException::withMessages([
                    'appointment' => 'Only pending or confirmed appointments can be edited.',
                ]);
            }

            $serviceId = (int) ($data['service_id'] ?? $appointment->service_id);
            $staffId = (int) ($data['staff_id'] ?? $appointment->staff_id);
            $date = (string) ($data['date'] ?? $this->appointmentDate($appointment));
            $startsAt = $this->normalizeTime((string) ($data['starts_at'] ?? $appointment->starts_at));

            if ($staffId <= 0) {
                throw ValidationException::withMessages([
                    'staff_id' => 'Select a staff member.',
                ]);
            }

            $service = Service::query()->findOrFail($serviceId);
            $staff = Staff::query()->findOrFail($staffId);

            $this->assertServiceCanBeBooked($service);
            $this->bookingValidator->validateStaffQualification($service, $staff);

            $isOpenCompletionCorrection = $this->isOpenCompletionCorrection($appointment);
            $isHistoricalTarget = $this->isHistoricalTarget($date, $startsAt);

            if ($isHistoricalTarget && !$isOpenCompletionCorrection) {
                throw ValidationException::withMessages([
                    'starts_at' => 'A scheduled appointment cannot be moved to a time in the past.',
                ]);
            }

            $package = $this->resolvePackage(
                appointment: $appointment,
                service: $service,
                staff: $staff,
                date: $date,
                requestedPackageId: array_key_exists('service_package_id', $data)
                    ? ($data['service_package_id'] !== null ? (int) $data['service_package_id'] : null)
                    : null,
                packageFieldWasSent: array_key_exists('service_package_id', $data),
                allowBeforeStart: $isHistoricalTarget && $isOpenCompletionCorrection,
                data: $data,
            );

            // A reopened historical completion is an audit correction, not a
            // live slot reservation. Future targets still go through the full
            // availability validator.
            if (!$isHistoricalTarget) {
                $this->bookingValidator->validateScheduledSlot(
                    service: $service,
                    staff: $staff,
                    date: $date,
                    startsAt: substr($startsAt, 0, 5),
                    ignoreAppointmentId: (int) $appointment->id,
                );
            }

            $warnings = [];
            $nextAllowedDate = null;

            if ($package) {
                [$warnings, $nextAllowedDate] = $this->validatePackageInterval(
                    package: $package,
                    appointmentDate: Carbon::parse($date)->startOfDay(),
                    ignoreAppointmentId: (int) $appointment->id,
                    override: (bool) ($data['interval_override'] ?? false),
                    overrideReason: $data['interval_override_reason'] ?? null,
                    historicalCorrection: $isHistoricalTarget && $isOpenCompletionCorrection,
                );
            }

            $before = $this->auditSnapshot($appointment);
            $serviceChanged = (int) $appointment->service_id !== (int) $service->id;
            $oldWasPackageBooking = $appointment->service_package_id !== null;
            $newIsPackageBooking = $package !== null;

            $newPrice = (float) $appointment->price;
            if ($serviceChanged) {
                $newPrice = (float) ($service->price ?? 0);
            }

            // Appointment-level payments belong to the single treatment itself.
            // Do not allow an edit to turn those payments into a package booking,
            // and do not reduce a single-treatment price below money already paid.
            $appointmentPaidMkd = $this->appointmentPaidMkd($appointment);
            if ($appointmentPaidMkd > 0.01) {
                if (!$oldWasPackageBooking && $newIsPackageBooking) {
                    throw ValidationException::withMessages([
                        'service_package_id' => 'This single appointment already has payments. Void/correct those payments before converting it to a package session.',
                    ]);
                }

                if (!$newIsPackageBooking) {
                    $newPriceMkd = round($newPrice * ServicePackage::EUR_TO_MKD, 2);
                    if ($appointmentPaidMkd - $newPriceMkd > 0.01) {
                        throw ValidationException::withMessages([
                            'service_id' => 'The new treatment price is lower than the amount already paid. Correct or void the payment before changing treatment.',
                        ]);
                    }
                }
            }

            $updatePayload = [
                'service_id' => $service->id,
                'service_package_id' => $package?->id,
                'staff_id' => $staff->id,
                'date' => $date,
                'starts_at' => $startsAt,
                'duration_minutes' => max(1, (int) ($service->duration_minutes ?? 60)),
                'price' => $newPrice,
                'notes' => array_key_exists('notes', $data)
                    ? $data['notes']
                    : $appointment->notes,
            ];

            // Changing the treatment is a deliberate change to the unsold/
            // future booking's commercial terms. Re-snapshot the new service
            // price. Date/staff/time edits leave the original sale terms alone.
            if ($serviceChanged) {
                $originalPrice = (float) ($service->price ?? $newPrice);
                $discountAmount = max($originalPrice - $newPrice, 0);

                $updatePayload = array_merge($updatePayload, [
                    'sale_original_price' => round($originalPrice, 2),
                    'sale_discount_type' => $discountAmount > 0 ? 'fixed' : null,
                    'sale_discount_value' => $discountAmount > 0 ? round($discountAmount, 2) : null,
                    'sale_discount_amount' => round($discountAmount, 2),
                    'sale_final_price' => round($newPrice, 2),
                ]);
            }

            $appointment->forceFill($updatePayload);
            $appointment->save();

            $after = $this->auditSnapshot($appointment->refresh());

            $logPayload = [
                'appointment_id' => $appointment->id,
                'user_id' => $admin->id,
                'action' => 'appointment_edited',
                'meta' => [
                    'before' => $before,
                    'after' => $after,
                    'interval_override' => (bool) ($data['interval_override'] ?? false),
                    'interval_override_reason' => $data['interval_override_reason'] ?? null,
                    'staff_override' => (bool) ($data['staff_override'] ?? false),
                    'staff_override_reason' => $data['staff_override_reason'] ?? null,
                    'historical_correction' => $isHistoricalTarget && $isOpenCompletionCorrection,
                    'warnings' => $warnings,
                ],
            ];

            // The current appointment_logs schema only guarantees
            // appointment_id, user_id, action and meta. Some older/newer
            // environments may also have a details column, so only write it
            // when that column actually exists.
            if (Schema::hasColumn('appointment_logs', 'details')) {
                $logPayload['details'] = 'Admin edited a scheduled appointment.';
            }

            AppointmentLog::query()->create($logPayload);

            $appointment->load([
                'service.category',
                'staff',
                'user',
                'package.service',
                'package.logs' => fn ($query) => $query->whereNull('voided_at')->latest('occurred_on'),
            ]);

            return [
                'appointment' => $appointment,
                'warnings' => array_values(array_unique($warnings)),
                'next_allowed_date' => $nextAllowedDate,
            ];
        });
    }

    private function resolvePackage(
        Appointment $appointment,
        Service $service,
        Staff $staff,
        string $date,
        ?int $requestedPackageId,
        bool $packageFieldWasSent,
        bool $allowBeforeStart,
        array $data,
    ): ?ServicePackage {
        $usageType = (string) ($service->usage_type ?? ($service->is_package ? Service::USAGE_SESSION : Service::USAGE_SINGLE));

        if ($usageType === Service::USAGE_SINGLE && !$service->is_package) {
            if ($packageFieldWasSent && $requestedPackageId !== null) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'Single treatments cannot be linked to a package.',
                ]);
            }

            return null;
        }

        if ($usageType !== Service::USAGE_SESSION) {
            throw ValidationException::withMessages([
                'service_id' => 'This service cannot be used as an appointment-based session package.',
            ]);
        }

        if (!$appointment->user_id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'A registered client is required for a package appointment.',
            ]);
        }

        // When the service has not changed, keep the currently attached package
        // unless the caller explicitly selects another package.
        $packageId = $requestedPackageId;
        if (!$packageFieldWasSent && (int) $appointment->service_id === (int) $service->id) {
            $packageId = $appointment->service_package_id ? (int) $appointment->service_package_id : null;
        }

        if (!$packageId) {
            throw ValidationException::withMessages([
                'service_package_id' => 'Select the exact client package for this session.',
            ]);
        }

        $package = ServicePackage::query()
            ->lockForUpdate()
            ->findOrFail($packageId);

        if ((int) $package->user_id !== (int) $appointment->user_id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package does not belong to this client.',
            ]);
        }

        if ((int) $package->service_id !== (int) $service->id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package is for a different treatment.',
            ]);
        }

        try {
            $package->assertUsableOn($date, $allowBeforeStart);
        } catch (\LogicException $exception) {
            throw ValidationException::withMessages([
                'service_package_id' => $exception->getMessage(),
            ]);
        }

        if (!$package->isSessionsType()) {
            throw ValidationException::withMessages([
                'service_package_id' => 'Only session packages can be linked to appointments.',
            ]);
        }

        if ((int) ($package->remaining_sessions ?? 0) < 1) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package has no sessions remaining.',
            ]);
        }

        $this->applySameStaffRule($package, $staff, $data);

        return $package;
    }

    private function applySameStaffRule(ServicePackage $package, Staff $staff, array $data): void
    {
        if ($package->staffPolicy() !== Service::STAFF_SAME) {
            return;
        }

        if (!$package->assigned_staff_id) {
            $package->assigned_staff_id = $staff->id;
            $package->save();
            return;
        }

        if ((int) $package->assigned_staff_id === (int) $staff->id) {
            return;
        }

        $hasCompletedUsage = $package->activeUsageLogs()
            ->where('usage_type', Service::USAGE_SESSION)
            ->exists();

        if ($hasCompletedUsage && !(bool) ($data['staff_override'] ?? false)) {
            throw ValidationException::withMessages([
                'staff_id' => 'This package is locked to its assigned staff member. An admin override and reason are required.',
            ]);
        }

        if ($hasCompletedUsage && blank($data['staff_override_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'staff_override_reason' => 'A reason is required to change staff after the first completed session.',
            ]);
        }

        $package->assigned_staff_id = $staff->id;
        $package->save();
    }

    /**
     * @return array{0: array<int, string>, 1: ?string}
     */
    private function validatePackageInterval(
        ServicePackage $package,
        Carbon $appointmentDate,
        int $ignoreAppointmentId,
        bool $override,
        ?string $overrideReason,
        bool $historicalCorrection = false,
    ): array {
        $minimumDays = max(0, (int) ($package->snapshot_minimum_interval_days ?? 0));

        if ($minimumDays === 0) {
            return [[], null];
        }

        $usageDates = $package->activeUsageLogs()
            ->where('usage_type', Service::USAGE_SESSION)
            ->pluck('occurred_on')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        $appointmentDates = $package->appointments()
            ->whereKeyNot($ignoreAppointmentId)
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_COMPLETED,
            ])
            ->pluck('date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        $eventDates = $usageDates
            ->merge($appointmentDates)
            ->unique()
            ->sort()
            ->values();

        $selectedDate = $appointmentDate->toDateString();
        $previousDateValue = $eventDates
            ->filter(fn (string $date) => $date <= $selectedDate)
            ->last();
        $nextDateValue = $eventDates
            ->first(fn (string $date) => $date > $selectedDate);

        $conflicts = [];
        $nextAllowedDate = null;

        if ($previousDateValue) {
            $previousDate = Carbon::parse($previousDateValue)->startOfDay();
            $nextAllowed = $previousDate->copy()->addDays($minimumDays);
            $nextAllowedDate = $nextAllowed->toDateString();

            if ($appointmentDate->lt($nextAllowed)) {
                $conflicts[] = "The package requires {$minimumDays} day(s) between sessions. The next allowed date is {$nextAllowedDate}.";
            }
        }

        if ($nextDateValue) {
            $nextDate = Carbon::parse($nextDateValue)->startOfDay();
            $latestAllowedBeforeNext = $nextDate->copy()->subDays($minimumDays);

            if ($appointmentDate->gt($latestAllowedBeforeNext)) {
                $conflicts[] = "This appointment is too close to the next package session on {$nextDate->toDateString()}.";
            }
        }

        if (!$conflicts) {
            return [[], $nextAllowedDate];
        }

        if ($historicalCorrection) {
            return [$conflicts, $nextAllowedDate];
        }

        if (!$override) {
            throw ValidationException::withMessages([
                'date' => implode(' ', $conflicts),
                'next_allowed_date' => $nextAllowedDate ?? '',
            ]);
        }

        if (blank($overrideReason)) {
            throw ValidationException::withMessages([
                'interval_override_reason' => 'A reason is required to override the package interval.',
            ]);
        }

        return [array_merge($conflicts, ["Interval override applied: {$overrideReason}"]), $nextAllowedDate];
    }

    private function assertServiceCanBeBooked(Service $service): void
    {
        $usageType = (string) ($service->usage_type ?? ($service->is_package ? Service::USAGE_SESSION : Service::USAGE_SINGLE));
        $requiresAppointment = (bool) ($service->requires_appointment ?? $service->is_bookable);

        if (!$service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => 'The selected treatment is inactive.',
            ]);
        }

        if (!$requiresAppointment || $usageType === Service::USAGE_MINUTES) {
            throw ValidationException::withMessages([
                'service_id' => 'This treatment does not use appointments.',
            ]);
        }
    }

    private function isHistoricalTarget(string $date, string $startsAt): bool
    {
        $timezone = config('clinic.timezone', config('app.timezone'));
        $selected = Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$startsAt}", $timezone);

        return $selected->lt(Carbon::now($timezone));
    }

    private function isOpenCompletionCorrection(Appointment $appointment): bool
    {
        if ($appointment->status !== Appointment::STATUS_CONFIRMED) {
            return false;
        }

        $logs = $appointment->logs()
            ->orderByDesc('id')
            ->get(['id', 'action', 'meta']);

        $lastReverseId = optional($logs->firstWhere('action', 'completion_reversed'))->id ?? 0;
        if ($lastReverseId <= 0) {
            return false;
        }

        // A later completion closes the correction window. Appointment logs are
        // loaded in PHP so this works consistently on both SQLite tests and MySQL.
        $lastCompletionId = $logs
            ->filter(function (AppointmentLog $log) {
                if ($log->action !== 'status_changed') {
                    return false;
                }

                return ($log->meta['to'] ?? null) === Appointment::STATUS_COMPLETED;
            })
            ->max('id') ?? 0;

        return $lastReverseId > $lastCompletionId;
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        throw ValidationException::withMessages([
            'starts_at' => 'Time must use HH:MM format.',
        ]);
    }

    private function appointmentDate(Appointment $appointment): string
    {
        return $appointment->date instanceof Carbon
            ? $appointment->date->toDateString()
            : Carbon::parse($appointment->date)->toDateString();
    }

    private function appointmentPaidMkd(Appointment $appointment): float
    {
        return round(
            PackagePayment::query()
                ->where('appointment_id', $appointment->id)
                ->whereNull('voided_at')
                ->get()
                ->sum(function (PackagePayment $payment) {
                    if ($payment->amount_mkd !== null) {
                        return (float) $payment->amount_mkd;
                    }

                    $amount = (float) $payment->amount;
                    $currency = strtoupper((string) ($payment->currency ?: 'EUR'));

                    if ($currency === 'EUR') {
                        $rate = (float) ($payment->exchange_rate ?: ServicePackage::EUR_TO_MKD);
                        return $amount * $rate;
                    }

                    return $amount;
                }),
            2,
        );
    }

    private function auditSnapshot(Appointment $appointment): array
    {
        return [
            'service_id' => $appointment->service_id ? (int) $appointment->service_id : null,
            'service_package_id' => $appointment->service_package_id ? (int) $appointment->service_package_id : null,
            'staff_id' => $appointment->staff_id ? (int) $appointment->staff_id : null,
            'date' => $this->appointmentDate($appointment),
            'starts_at' => substr((string) $appointment->starts_at, 0, 8),
            'duration_minutes' => (int) $appointment->duration_minutes,
            'price' => (float) $appointment->price,
            'sale_original_price' => $appointment->sale_original_price !== null ? (float) $appointment->sale_original_price : null,
            'sale_discount_type' => $appointment->sale_discount_type,
            'sale_discount_value' => $appointment->sale_discount_value !== null ? (float) $appointment->sale_discount_value : null,
            'sale_discount_amount' => $appointment->sale_discount_amount !== null ? (float) $appointment->sale_discount_amount : null,
            'sale_final_price' => $appointment->sale_final_price !== null ? (float) $appointment->sale_final_price : null,
            'notes' => $appointment->notes,
            'status' => $appointment->status,
        ];
    }
}
