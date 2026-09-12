<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Staff;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BookingGroupStaffService
{
    /**
     * Return active staff who are qualified for every selected treatment.
     *
     * Existing same-staff packages only become a hard staff lock after a
     * completed/non-voided session exists, matching AdminClientAppointmentService.
     * Before the first completed use, an assigned staff member can still be
     * changed by an admin booking and the package will be re-assigned.
     *
     * @param  array<int,int>  $serviceIds
     * @param  array<int,int>  $packageIds
     * @return array{
     *   staff: array<int,array{id:int,name:string,email:?string,phone:?string,is_active:bool,available:null}>,
     *   locked_staff_id: ?int,
     *   constraint_message: ?string
     * }
     */
    public function eligibleStaff(array $serviceIds, array $packageIds = []): array
    {
        $serviceIds = $this->normaliseIds($serviceIds);
        $packageIds = $this->normaliseIds($packageIds);

        if (count($serviceIds) < 2) {
            throw ValidationException::withMessages([
                'service_ids' => 'A joined booking requires at least two treatments.',
            ]);
        }

        $this->assertServicesExistAndBookable($serviceIds);

        $query = Staff::query()->where('is_active', true);
        foreach ($serviceIds as $serviceId) {
            $query->whereHas('services', fn ($q) => $q->where('services.id', $serviceId));
        }

        [$lockedStaffId, $constraintMessage, $conflict] = $this->sameStaffConstraint($packageIds);

        if ($conflict) {
            return [
                'staff' => [],
                'locked_staff_id' => null,
                'constraint_message' => $constraintMessage,
            ];
        }

        if ($lockedStaffId) {
            $query->whereKey($lockedStaffId);
        }

        $staff = $query
            ->select('id', 'name', 'email', 'phone', 'is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Staff $item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'email' => $item->email ? (string) $item->email : null,
                'phone' => $item->phone ? (string) $item->phone : null,
                'is_active' => (bool) $item->is_active,
                'available' => null,
            ])
            ->values()
            ->all();

        if ($lockedStaffId && !$staff) {
            $constraintMessage = 'The staff member locked to this package is not qualified for every treatment in the joined visit.';
        }

        return [
            'staff' => $staff,
            'locked_staff_id' => $lockedStaffId,
            'constraint_message' => $constraintMessage,
        ];
    }

    /**
     * Enforce the one-staff V1 rule before any booking-group rows are written.
     *
     * @param array<int,array<string,mixed>> $treatments
     */
    public function assertStaffEligible(array $treatments, int $staffId): void
    {
        /** @var Staff|null $staff */
        $staff = Staff::query()->find($staffId);
        if (!$staff || !$staff->is_active) {
            throw ValidationException::withMessages([
                'staff_id' => 'The selected staff member is not active.',
            ]);
        }

        $serviceIds = $this->normaliseIds(array_map(
            fn (array $treatment) => (int) ($treatment['service_id'] ?? 0),
            $treatments,
        ));

        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        foreach ($serviceIds as $serviceId) {
            /** @var Service|null $service */
            $service = $services->get($serviceId);
            if (!$service) {
                throw ValidationException::withMessages([
                    'staff_id' => "Service {$serviceId} could not be found.",
                ]);
            }

            if ($service->staff()->whereKey($staff->id)->doesntExist()) {
                throw ValidationException::withMessages([
                    'staff_id' => "{$staff->name} is not qualified for {$service->name}. Choose one staff member who can perform every treatment in this joined visit.",
                ]);
            }
        }

        foreach ($treatments as $index => $treatment) {
            $treatment = (array) $treatment;
            if (($treatment['purchase_type'] ?? null) !== 'existing_package') {
                continue;
            }

            $packageId = (int) ($treatment['service_package_id'] ?? 0);
            if ($packageId < 1) {
                continue;
            }

            /** @var ServicePackage|null $package */
            $package = ServicePackage::query()->find($packageId);
            if (!$package || !$this->packageHasHardSameStaffLock($package)) {
                continue;
            }

            if ((int) $package->assigned_staff_id === (int) $staff->id) {
                continue;
            }

            if (!(bool) ($treatment['staff_override'] ?? false)) {
                throw ValidationException::withMessages([
                    "treatments.{$index}.staff_id" => 'This package is locked to its assigned staff member. The joined visit must use that same staff member or an admin override is required.',
                ]);
            }

            if (blank($treatment['staff_override_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    "treatments.{$index}.staff_override_reason" => 'A reason is required to override the same-staff package rule.',
                ]);
            }
        }
    }

    /** @param array<int,int> $serviceIds */
    private function assertServicesExistAndBookable(array $serviceIds): void
    {
        $services = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');

        foreach ($serviceIds as $serviceId) {
            /** @var Service|null $service */
            $service = $services->get($serviceId);
            if (!$service) {
                throw ValidationException::withMessages([
                    'service_ids' => "Service {$serviceId} could not be found.",
                ]);
            }

            $usageType = (string) ($service->usage_type ?? ($service->is_package ? 'session' : 'single'));
            $requiresAppointment = (bool) ($service->requires_appointment ?? $service->is_bookable);

            if (!$service->is_active || !$requiresAppointment || $usageType === Service::USAGE_MINUTES) {
                throw ValidationException::withMessages([
                    'service_ids' => "{$service->name} cannot be scheduled as an appointment.",
                ]);
            }
        }
    }

    /**
     * @param array<int,int> $packageIds
     * @return array{0:?int,1:?string,2:bool}
     */
    private function sameStaffConstraint(array $packageIds): array
    {
        if (!$packageIds) {
            return [null, null, false];
        }

        /** @var Collection<int,ServicePackage> $packages */
        $packages = ServicePackage::query()
            ->whereIn('id', $packageIds)
            ->get();

        $lockedIds = $packages
            ->filter(fn (ServicePackage $package) => $this->packageHasHardSameStaffLock($package))
            ->pluck('assigned_staff_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($lockedIds->count() > 1) {
            return [
                null,
                'The selected packages are locked to different staff members, so they cannot be booked together as one V1 joined visit.',
                true,
            ];
        }

        if ($lockedIds->count() === 1) {
            return [
                (int) $lockedIds->first(),
                'A same-staff package in this visit is already locked to its assigned staff member.',
                false,
            ];
        }

        return [null, null, false];
    }

    private function packageHasHardSameStaffLock(ServicePackage $package): bool
    {
        $policy = (string) ($package->snapshot_staff_policy
            ?: $package->service?->staff_policy
            ?: Service::STAFF_ANY_QUALIFIED);

        if ($policy !== Service::STAFF_SAME || !$package->assigned_staff_id) {
            return false;
        }

        return $package->logs()
            ->where('usage_type', Service::USAGE_SESSION)
            ->whereNull('voided_at')
            ->exists();
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function normaliseIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
