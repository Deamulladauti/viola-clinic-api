<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\Offer;
use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminClientAppointmentService
{
    public function __construct(
        private readonly AppointmentBookingValidator $bookingValidator,
        private readonly AppointmentCompletionService $appointmentCompletionService,
        private readonly ManualSalePricingService $pricing,
        private readonly OfferPricingService $offerPricing,
    ) {
    }

    /**
     * @return array{appointment: Appointment, package: ?ServicePackage, warnings: array<int, string>, next_allowed_date: ?string}
     */
    public function create(User $client, array $data, User $admin): array
    {
        return DB::transaction(function () use ($client, $data, $admin) {
            $this->assertClientRole($client);

            $service = Service::query()->findOrFail((int) $data['service_id']);
            $staff = Staff::query()->findOrFail((int) $data['staff_id']);
            $purchaseType = (string) $data['purchase_type'];
            $timezone = config('clinic.timezone', config('app.timezone'));
            $selectedDate = Carbon::createFromFormat('Y-m-d', (string) $data['date'], $timezone)->startOfDay();
            $today = Carbon::today($timezone);
            $requestedStatus = $this->normalizeStatus($data['status'] ?? null);
            $isHistorical = $selectedDate->lt($today)
                || ($selectedDate->lte($today) && in_array($requestedStatus, [
                    Appointment::STATUS_COMPLETED,
                    Appointment::STATUS_CANCELLED,
                    Appointment::STATUS_NO_SHOW,
                ], true));

            $warnings = [];
            $nextAllowedDate = null;

            $this->assertServiceCanBeBooked($service);
            $this->bookingValidator->validateStaffQualification($service, $staff);
            $status = $this->resolveStatus($requestedStatus, $isHistorical);
            $startsAt = $this->resolveStartsAt($data['starts_at'] ?? null, $isHistorical);

            if (!$isHistorical) {
                $this->bookingValidator->validateScheduledSlot(
                    $service,
                    $staff,
                    $selectedDate->toDateString(),
                    $startsAt,
                );
            }

            $package = match ($purchaseType) {
                'single' => $this->resolveSinglePurchase($service),
                'existing_package' => $this->resolveExistingPackage(
                    $client,
                    $service,
                    (int) $data['service_package_id'],
                    $selectedDate,
                    $isHistorical,
                ),
                'new_package' => $this->createPackage($client, $service, $data, $selectedDate),
                default => throw ValidationException::withMessages([
                    'purchase_type' => 'Unsupported purchase type.',
                ]),
            };

            if ($package) {
                if (
                    $isHistorical
                    && $package->starts_on
                    && $selectedDate->lt($package->starts_on->copy()->startOfDay())
                ) {
                    $warnings[] = 'Historical appointment is earlier than the package start date. It was saved as an admin historical import.';
                }

                $this->applySameStaffRule($package, $staff, $data);

                [$intervalWarnings, $nextAllowedDate] = $this->validatePackageInterval(
                    $package,
                    $selectedDate,
                    $isHistorical,
                    (bool) ($data['interval_override'] ?? false),
                    $data['interval_override_reason'] ?? null,
                );

                $warnings = array_merge($warnings, $intervalWarnings);
            }

            $initialStatus = $status === Appointment::STATUS_COMPLETED
                ? Appointment::STATUS_CONFIRMED
                : $status;

            $singleOffer = null;
            $singleSaleTerms = null;

            if ($purchaseType === 'single' && !empty($data['offer_id'])) {
                $singleOffer = Offer::query()
                    ->with('services:id')
                    ->findOrFail((int) $data['offer_id']);
                $singleSaleTerms = $this->offerPricing->resolve($singleOffer, $service);
            } elseif (
                $purchaseType === 'single'
                && (!empty($data['sale_discount_type']) || array_key_exists('sale_discount_value', $data))
            ) {
                $singleSaleTerms = $this->pricing->calculate(
                    originalPrice: (float) ($service->price ?? 0),
                    discountType: $data['sale_discount_type'] ?? null,
                    discountValue: $data['sale_discount_value'] ?? null,
                );
            }

            $appointmentPayload = [
                'user_id' => $client->id,
                'service_id' => $service->id,
                'service_package_id' => $package?->id,
                'staff_id' => $staff->id,
                'date' => $selectedDate->toDateString(),
                'starts_at' => $startsAt,
                'duration_minutes' => max(1, (int) ($service->duration_minutes ?? 60)),
                'price' => $singleSaleTerms
                    ? $singleSaleTerms['final_price']
                    : (float) ($data['price'] ?? $service->price ?? 0),
                'customer_name' => $client->name,
                'customer_phone' => $client->phone,
                'customer_email' => $client->email,
                'status' => $initialStatus,
                'notes' => $data['notes'] ?? null,
                'admin_notes' => $data['notes'] ?? null,
                'reference_code' => $this->newReferenceCode(),
                'source' => $isHistorical ? 'manual_import' : 'admin_booking',
            ];

            if ($singleSaleTerms) {
                $appointmentPayload = array_merge($appointmentPayload, [
                    'sale_original_price' => $singleSaleTerms['original_price'],
                    'sale_discount_type' => $singleSaleTerms['discount_type'],
                    'sale_discount_value' => $singleSaleTerms['discount_value'],
                    'sale_discount_amount' => $singleSaleTerms['discount_amount'],
                    'sale_final_price' => $singleSaleTerms['final_price'],
                    'sale_offer_id' => $singleOffer?->id,
                    'sale_offer_name' => $singleOffer?->name,
                ]);
            }

            $appointment = new Appointment();
            $appointment->forceFill($appointmentPayload);
            $appointment->save();

            if ($status === Appointment::STATUS_COMPLETED) {
                $this->appointmentCompletionService->complete(
                    appointment: $appointment,
                    actorUserId: $admin->id,
                    note: $data['notes'] ?? null,
                    source: $isHistorical
                        ? PackageLog::SOURCE_IMPORTED
                        : PackageLog::SOURCE_AUTOMATIC,
                );
                $appointment->refresh();
            }

            $logPayload = [
                'appointment_id' => $appointment->id,
                'user_id' => $admin->id,
                'action' => 'admin_client_appointment_created',
                'meta' => [
                    'purchase_type' => $purchaseType,
                    'source' => $appointment->source,
                    'service_package_id' => $package?->id,
                    'sale_offer_id' => $appointment->sale_offer_id ?? $package?->sale_offer_id,
                    'sale_offer_name' => $appointment->sale_offer_name ?? $package?->sale_offer_name,
                    'interval_override' => (bool) ($data['interval_override'] ?? false),
                    'interval_override_reason' => $data['interval_override_reason'] ?? null,
                    'staff_override' => (bool) ($data['staff_override'] ?? false),
                    'staff_override_reason' => $data['staff_override_reason'] ?? null,
                    'warnings' => $warnings,
                ],
            ];

            if (Schema::hasColumn('appointment_logs', 'details')) {
                $logPayload['details'] = $isHistorical
                    ? 'Admin added a historical client visit through the unified appointment API.'
                    : 'Admin created a client appointment through the unified appointment API.';
            }

            AppointmentLog::query()->create($logPayload);

            $appointment->load([
                'service.category',
                'staff',
                'user',
                'package.service',
                'package.logs' => fn ($query) => $query->whereNull('voided_at')->latest('occurred_on'),
            ]);

            $package?->refresh();

            return [
                'appointment' => $appointment,
                'package' => $package,
                'warnings' => array_values(array_unique($warnings)),
                'next_allowed_date' => $nextAllowedDate,
            ];
        });
    }

    private function assertClientRole(User $client): void
    {
        if (method_exists($client, 'hasRole') && !$client->hasRole('client')) {
            throw ValidationException::withMessages([
                'client' => 'Appointments can only be created for users with the client role.',
            ]);
        }
    }

    private function assertServiceCanBeBooked(Service $service): void
    {
        $usageType = (string) ($service->usage_type ?? ($service->is_package ? 'session' : 'single'));
        $requiresAppointment = (bool) ($service->requires_appointment ?? $service->is_bookable);

        if (!$service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => 'The selected service is inactive.',
            ]);
        }

        if (!$requiresAppointment || $usageType === 'minutes') {
            throw ValidationException::withMessages([
                'service_id' => 'This service does not use appointments. Record quantity usage instead.',
            ]);
        }
    }

    private function resolveSinglePurchase(Service $service): null
    {
        $usageType = (string) ($service->usage_type ?? ($service->is_package ? 'session' : 'single'));

        if ($usageType !== 'single' || $service->is_package) {
            throw ValidationException::withMessages([
                'purchase_type' => 'This service must be booked through an existing or newly purchased package.',
            ]);
        }

        return null;
    }

    private function resolveExistingPackage(
        User $client,
        Service $service,
        int $packageId,
        Carbon $appointmentDate,
        bool $isHistorical,
    ): ServicePackage {
        $package = ServicePackage::query()
            ->lockForUpdate()
            ->findOrFail($packageId);

        $this->assertPackageCanCoverAppointment(
            $package,
            $client,
            $service,
            $appointmentDate,
            $isHistorical,
        );

        return $package;
    }

    private function createPackage(
        User $client,
        Service $service,
        array $data,
        Carbon $appointmentDate,
    ): ServicePackage {
        $usageType = (string) ($service->usage_type ?? ($service->is_package ? Service::USAGE_SESSION : Service::USAGE_SINGLE));
        $deductionMethod = (string) ($service->deduction_method ?? Service::DEDUCTION_AUTOMATIC);
        $totalSessions = (int) ($service->total_sessions ?? 0);

        if (
            !$service->is_package
            || $usageType !== Service::USAGE_SESSION
            || $deductionMethod !== Service::DEDUCTION_AUTOMATIC
            || $totalSessions < 1
        ) {
            throw ValidationException::withMessages([
                'purchase_type' => 'Only automatic session-package services can be purchased while creating an appointment.',
            ]);
        }

        $packageData = (array) ($data['package'] ?? []);
        $timezone = config('clinic.timezone', config('app.timezone'));
        $today = Carbon::today($timezone);
        $defaultStartsOn = $appointmentDate->lt($today)
            ? $appointmentDate->copy()
            : $today;

        $startsOn = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($packageData['starts_on'] ?? $defaultStartsOn->toDateString()),
            $timezone,
        )->startOfDay();

        if ($appointmentDate->lt($startsOn)) {
            throw ValidationException::withMessages([
                'date' => 'The appointment cannot be before the package start date.',
            ]);
        }

        $packageOffer = null;
        $packageSaleTerms = null;

        if (!empty($packageData['offer_id'])) {
            $packageOffer = Offer::query()
                ->with('services:id')
                ->findOrFail((int) $packageData['offer_id']);
            $packageSaleTerms = $this->offerPricing->resolve($packageOffer, $service);
        } elseif (
            !empty($packageData['sale_discount_type'])
            || array_key_exists('sale_discount_value', $packageData)
        ) {
            $packageSaleTerms = $this->pricing->calculate(
                originalPrice: (float) ($service->price ?? 0),
                discountType: $packageData['sale_discount_type'] ?? null,
                discountValue: $packageData['sale_discount_value'] ?? null,
            );
        }

        $packagePrice = $packageSaleTerms
            ? $packageSaleTerms['final_price']
            : (float) ($packageData['price_total'] ?? $service->price ?? 0);

        $packagePayload = [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => $totalSessions,
            'snapshot_total_minutes' => null,
            'remaining_sessions' => $totalSessions,
            'remaining_minutes' => null,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'snapshot_minimum_interval_days' => (int) ($service->minimum_interval_days ?? 0),
            'snapshot_deduction_method' => $deductionMethod,
            'snapshot_staff_policy' => (string) ($service->staff_policy ?? 'any_qualified_staff'),
            'snapshot_duration_minutes' => max(1, (int) ($service->duration_minutes ?? 60)),
            'assigned_staff_id' => null,
            'price_total' => $packagePrice,
            'price_paid' => 0,
            'currency' => strtoupper((string) ($packageData['currency'] ?? 'EUR')),
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => $startsOn->toDateString(),
            // Kept nullable in the database for backwards compatibility only.
            'expires_on' => null,
            'notes' => $packageData['notes'] ?? null,
        ];

        if ($packageSaleTerms) {
            $packagePayload = array_merge($packagePayload, [
                'sale_original_price' => $packageSaleTerms['original_price'],
                'sale_discount_type' => $packageSaleTerms['discount_type'],
                'sale_discount_value' => $packageSaleTerms['discount_value'],
                'sale_discount_amount' => $packageSaleTerms['discount_amount'],
                'sale_final_price' => $packageSaleTerms['final_price'],
                'sale_offer_id' => $packageOffer?->id,
                'sale_offer_name' => $packageOffer?->name,
            ]);
        }

        $package = new ServicePackage();
        $package->forceFill($packagePayload);
        $package->save();

        return $package;
    }

    private function assertPackageCanCoverAppointment(
        ServicePackage $package,
        User $client,
        Service $service,
        Carbon $appointmentDate,
        bool $allowBeforeStart = false,
    ): void {
        if ((int) $package->user_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package does not belong to this client.',
            ]);
        }

        if ((int) $package->service_id !== (int) $service->id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package is for a different service.',
            ]);
        }

        if ($package->status !== ServicePackage::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package is not active.',
            ]);
        }

        $usageType = (string) ($package->snapshot_usage_type ?? $service->usage_type ?? 'session');
        $deductionMethod = (string) ($package->snapshot_deduction_method ?? $service->deduction_method ?? 'automatic_on_completion');

        if ($usageType !== 'session' || $deductionMethod !== 'automatic_on_completion') {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package is not an appointment-based session package.',
            ]);
        }

        if ((int) ($package->remaining_sessions ?? 0) < 1) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package has no sessions remaining.',
            ]);
        }

        if (
            ! $allowBeforeStart
            && $package->starts_on
            && $appointmentDate->lt($package->starts_on->copy()->startOfDay())
        ) {
            throw ValidationException::withMessages([
                'date' => 'The appointment is before the package start date.',
            ]);
        }

    }

    private function applySameStaffRule(ServicePackage $package, Staff $staff, array $data): void
    {
        $policy = (string) ($package->snapshot_staff_policy ?? 'any_qualified_staff');

        if ($policy !== 'same_staff') {
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

        $hasCompletedUsage = $package->logs()
            ->where('usage_type', 'session')
            ->whereNull('voided_at')
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
        bool $isHistorical,
        bool $override,
        ?string $overrideReason,
    ): array {
        $minimumDays = max(0, (int) ($package->snapshot_minimum_interval_days ?? 0));

        if ($minimumDays === 0) {
            return [[], null];
        }

        $usageDates = $package->logs()
            ->where('usage_type', 'session')
            ->whereNull('voided_at')
            ->pluck('occurred_on')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        // Scheduled package appointments also reserve an interval. Without this,
        // multiple future sessions could be booked too close together before any
        // of them has been completed and written to the usage ledger.
        $appointmentDates = $package->appointments()
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_COMPLETED,
            ])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        $eventDates = $usageDates
            ->merge($appointmentDates)
            ->filter()
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

        if ($isHistorical) {
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

    private function resolveStatus(?string $requestedStatus, bool $isHistorical): string
    {
        if ($isHistorical) {
            $status = $requestedStatus ?: Appointment::STATUS_COMPLETED;
            $allowed = [
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ];

            if (!in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Historical visits must be completed, cancelled, or no-show.',
                ]);
            }

            return $status;
        }

        $status = $requestedStatus ?: Appointment::STATUS_CONFIRMED;

        if (!in_array($status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Scheduled appointments must be pending or confirmed.',
            ]);
        }

        return $status;
    }

    private function resolveStartsAt(?string $startsAt, bool $isHistorical): string
    {
        if (!$isHistorical && blank($startsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Time is required for scheduled appointments.',
            ]);
        }

        return $startsAt ?: '00:00';
    }

    private function normalizeStatus(?string $status): ?string
    {
        return $status === 'no-show' ? Appointment::STATUS_NO_SHOW : $status;
    }

    private function newReferenceCode(): string
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (Appointment::query()->where('reference_code', $code)->exists());

        return $code;
    }
}
