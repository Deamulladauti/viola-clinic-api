<?php

namespace App\Services;

use App\Events\ClinicNotificationEvent;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Task 30 event boundary. Call ONLY after a successful appointment mutation.
 * Task 31-33 will add recipient-specific listeners; Task 35 adds push delivery.
 */
final class ClinicNotificationEvents
{
    public const CREATED = 'appointment.created';
    public const RESCHEDULED = 'appointment.rescheduled';
    public const CANCELLED = 'appointment.cancelled';
    public const STAFF_REASSIGNED = 'appointment.staff_reassigned';
    public const REMINDER_DUE = 'appointment.reminder_due';

    public const TYPES = [
        self::CREATED, self::RESCHEDULED, self::CANCELLED,
        self::STAFF_REASSIGNED, self::REMINDER_DUE,
    ];

    /**
     * $changes is an explicit before/after snapshot, e.g.:
     * ['date' => ['from' => '2026-10-01', 'to' => '2026-10-02']].
     * Do not include sensitive client details in this payload.
     */
    public function emit(
        string $type,
        Appointment $appointment,
        string $origin,
        array $changes = [],
        ?int $previousStaffId = null,
        ?string $oldStatus = null,
    ): void {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported clinic notification event: '.$type);
        }

        // Capture scalars now: avoid relying on mutable models inside callbacks.
        $event = new ClinicNotificationEvent(
            type: $type,
            appointmentId: (int) $appointment->getKey(),
            bookingGroupId: $appointment->booking_group_id !== null ? (int) $appointment->booking_group_id : null,
            clientId: $appointment->user_id !== null ? (int) $appointment->user_id : null,
            staffId: $appointment->staff_id !== null ? (int) $appointment->staff_id : null,
            previousStaffId: $previousStaffId,
            oldStatus: $oldStatus,
            newStatus: $appointment->status,
            origin: $origin,
            changes: $changes,
        );

        // Prevent downstream delivery for a rolled-back booking/group transaction.
        DB::afterCommit(static fn () => event($event));
    }

    /**
     * Joined visit: emit ONCE for the group representative, not once per leg.
     * The eventual listener can load all appointments by booking_group_id.
     */
    public function emitForGroup(string $type, iterable $appointments, string $origin, array $changes = []): void
    {
        $first = null;
        foreach ($appointments as $appointment) {
            if (!$appointment instanceof Appointment) {
                throw new InvalidArgumentException('Group must contain appointments.');
            }
            $first ??= $appointment;
            if ($first->booking_group_id === null || $appointment->booking_group_id !== $first->booking_group_id) {
                throw new InvalidArgumentException('All appointments must belong to the same booking group.');
            }
        }
        if ($first === null) {
            throw new InvalidArgumentException('Cannot emit a notification event for an empty group.');
        }
        $this->emit($type, $first, $origin, $changes);
    }
}
