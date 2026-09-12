<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Models\PackageLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminBookingGroupCompletionService
{
    public function __construct(
        private readonly AppointmentCompletionService $completionService,
    ) {
    }

    /**
     * Complete every treatment that belongs to one joined clinic visit.
     *
     * Each underlying appointment is still completed through the existing
     * AppointmentCompletionService, so package deduction, same-staff rules,
     * minimum intervals, ledger creation and status audit behavior stay in one
     * canonical place. The outer transaction makes the group completion atomic:
     * if any later treatment cannot be completed, all earlier deductions/status
     * changes from this request are rolled back as well.
     *
     * @return array{
     *   group: BookingGroup,
     *   appointments: \Illuminate\Support\Collection<int, Appointment>,
     *   usage: array<int, array<string, mixed>>,
     *   newly_completed_count: int
     * }
     */
    public function complete(
        BookingGroup $bookingGroup,
        User $admin,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($bookingGroup, $admin, $note) {
            $group = BookingGroup::query()
                ->lockForUpdate()
                ->findOrFail($bookingGroup->id);

            $appointments = Appointment::query()
                ->where('booking_group_id', $group->id)
                ->orderBy('date')
                ->orderBy('starts_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($appointments->count() < 2) {
                throw ValidationException::withMessages([
                    'booking_group' => 'A joined visit must contain at least two treatments.',
                ]);
            }

            $invalid = $appointments->first(
                fn (Appointment $appointment) => ! in_array($appointment->status, [
                    Appointment::STATUS_PENDING,
                    Appointment::STATUS_CONFIRMED,
                    Appointment::STATUS_COMPLETED,
                ], true),
            );

            if ($invalid) {
                throw ValidationException::withMessages([
                    'booking_group' => sprintf(
                        'Treatment #%d cannot be completed because its current status is %s.',
                        $invalid->id,
                        str_replace('_', ' ', (string) $invalid->status),
                    ),
                ]);
            }

            $usage = [];
            $newlyCompleted = 0;

            foreach ($appointments as $index => $appointment) {
                $wasCompleted = $appointment->status === Appointment::STATUS_COMPLETED;

                try {
                    $completed = $this->completionService->complete(
                        appointment: $appointment,
                        actorUserId: $admin->id,
                        note: $note,
                        source: $appointment->source === Appointment::SOURCE_MANUAL_IMPORT
                            ? PackageLog::SOURCE_IMPORTED
                            : PackageLog::SOURCE_AUTOMATIC,
                    );
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["appointments.{$index}.{$field}"] = $messages;
                    }

                    throw ValidationException::withMessages($errors ?: [
                        "appointments.{$index}" => 'This treatment could not be completed.',
                    ]);
                }

                if (! $wasCompleted) {
                    $newlyCompleted++;
                }

                $ledger = null;
                if ($completed->service_package_id) {
                    $ledger = PackageLog::query()
                        ->where('service_package_id', $completed->service_package_id)
                        ->where('appointment_id', $completed->id)
                        ->whereNull('voided_at')
                        ->orderByDesc('id')
                        ->first();
                }

                $usage[] = [
                    'appointment_id' => $completed->id,
                    'service_package_id' => $completed->service_package_id,
                    'package_log_id' => $ledger?->id,
                    'session_number' => $ledger?->session_number,
                    'used_sessions' => $ledger ? (int) $ledger->used_sessions : 0,
                ];
            }

            $group->load([
                'client:id,name,email,phone',
                'appointments.service',
                'appointments.staff',
                'appointments.package',
            ]);

            return [
                'group' => $group,
                'appointments' => $group->appointments,
                'usage' => $usage,
                'newly_completed_count' => $newlyCompleted,
            ];
        });
    }
}
