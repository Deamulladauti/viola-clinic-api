<?php

namespace App\Services;

use App\Models\PackageLog;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 API adapter around the Phase 1 package-usage domain service.
 *
 * Keeping the balance mutation and ledger creation in PackageUsageService
 * prevents the admin and staff APIs from developing a second implementation.
 */
class PackageQuantityUsageService
{
    public function __construct(private readonly PackageUsageService $packageUsageService)
    {
    }

    public function record(
        ServicePackage $package,
        int $minutes,
        string $occurredOn,
        ?Staff $staff,
        User $createdBy,
        ?string $note = null,
        string $source = PackageLog::SOURCE_MANUAL,
    ): PackageLog {
        if ($staff && ! $staff->is_active) {
            throw ValidationException::withMessages([
                'staff_id' => 'The selected staff member is not active.',
            ]);
        }

        try {
            $log = $this->packageUsageService->recordManualQuantityUsage(
                package: $package,
                quantity: $minutes,
                occurredOn: $occurredOn,
                staffId: $staff?->id,
                actorUserId: $createdBy->id,
                note: $note,
                source: $source,
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            // The Phase 1 domain service uses the generic key "amount". The
            // canonical Phase 2 endpoint calls the field "minutes".
            if (isset($errors['amount']) && ! isset($errors['minutes'])) {
                $errors['minutes'] = $errors['amount'];
                unset($errors['amount']);
            }

            throw ValidationException::withMessages($errors);
        }

        return $log->load(['package.service', 'staff']);
    }
}
