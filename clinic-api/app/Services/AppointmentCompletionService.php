<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\PackageLog;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentCompletionService
{
    public function __construct(private readonly PackageUsageService $packageUsageService)
    {
    }

    public function complete(
        Appointment $appointment,
        ?int $actorUserId,
        ?string $note = null,
        string $source = PackageLog::SOURCE_AUTOMATIC,
    ): Appointment {
        return DB::transaction(function () use ($appointment, $actorUserId, $note, $source) {
            $appointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            $appointment->loadMissing('service');
            $from = $appointment->status;

            if ($appointment->service?->usage_type === Service::USAGE_MINUTES) {
                throw ValidationException::withMessages([
                    'service_id' => 'Quantity/minute services must be recorded as walk-in package usage, not completed appointments.',
                ]);
            }

            if ($appointment->service?->usage_type === Service::USAGE_SESSION && ! $appointment->service_package_id) {
                throw ValidationException::withMessages([
                    'service_package_id' => 'A session-package appointment must be linked to the exact client package before completion.',
                ]);
            }

            if (! in_array($from, [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_COMPLETED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot complete an appointment from status '{$from}'.",
                ]);
            }

            if ($note !== null) {
                $appointment->notes = $note;
            }

            if ($appointment->service_package_id) {
                $this->packageUsageService->recordSessionForAppointment(
                    appointment: $appointment,
                    actorUserId: $actorUserId,
                    note: $note,
                    source: $source,
                    enforceInterval: $source !== PackageLog::SOURCE_IMPORTED,
                );
            }

            $appointment->status = Appointment::STATUS_COMPLETED;
            $appointment->save();

            if ($from !== Appointment::STATUS_COMPLETED) {
                AppointmentLog::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => $actorUserId,
                    'action' => 'status_changed',
                    'meta' => [
                        'from' => $from,
                        'to' => Appointment::STATUS_COMPLETED,
                        'package_usage_source' => $source,
                    ],
                ]);
            }

            return $appointment->refresh();
        });
    }

    public function reverseCompletion(
        Appointment $appointment,
        string $targetStatus,
        ?int $actorUserId,
        string $reason,
    ): Appointment {
        if ($targetStatus === Appointment::STATUS_COMPLETED || ! in_array($targetStatus, Appointment::statuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'A valid non-completed target status is required.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when correcting a completed appointment.',
            ]);
        }

        return DB::transaction(function () use ($appointment, $targetStatus, $actorUserId, $reason) {
            $appointment = Appointment::query()
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            if ($appointment->status !== Appointment::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Only a completed appointment can use the completion reversal workflow.',
                ]);
            }

            if ($appointment->service_package_id) {
                $this->packageUsageService->voidAppointmentUsage(
                    appointment: $appointment,
                    actorUserId: $actorUserId,
                    reason: $reason,
                );
            }

            $appointment->status = $targetStatus;
            $appointment->save();

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => $actorUserId,
                'action' => 'completion_reversed',
                'meta' => [
                    'from' => Appointment::STATUS_COMPLETED,
                    'to' => $targetStatus,
                    'reason' => $reason,
                ],
            ]);

            return $appointment->refresh();
        });
    }
}
