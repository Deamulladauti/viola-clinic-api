<?php

namespace App\Listeners;

use App\Events\ClinicNotificationEvent;
use App\Models\Appointment;
use App\Models\Staff;
use App\Notifications\StaffAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;

/** Database notifications to the user account attached to a Staff record. */
final class SendStaffClinicNotification
{
    public function handle(ClinicNotificationEvent $event): void
    {
        if (!in_array($event->type, [
            ClinicNotificationEvents::CREATED,
            ClinicNotificationEvents::RESCHEDULED,
            ClinicNotificationEvents::CANCELLED,
            ClinicNotificationEvents::STAFF_REASSIGNED,
        ], true)) {
            return;
        }

        $appointment = Appointment::query()->find($event->appointmentId);
        if (!$appointment) {
            return;
        }

        $appointments = $event->bookingGroupId
            ? Appointment::query()->where('booking_group_id', $event->bookingGroupId)
                ->orderBy('id')->with('service')->get()
            : collect([$appointment->loadMissing('service')]);
        if ($appointments->isEmpty() || (int) $appointments->first()->id !== $event->appointmentId) {
            return; // one message per joined visit
        }

        $serviceLabel = $appointments->map(fn ($a) => $a->service?->name ?? 'Treatment')
            ->unique()->implode(' + ');
        $date = $appointment->date?->format('Y-m-d') ?? '';
        $time = (string) $appointment->starts_at;

        $targets = [];
        if ($event->type === ClinicNotificationEvents::STAFF_REASSIGNED) {
            if ($event->previousStaffId && $event->previousStaffId !== $event->staffId) {
                $targets[$event->previousStaffId] = 'removed';
            }
            if ($event->staffId && $event->previousStaffId !== $event->staffId) {
                $targets[$event->staffId] = 'assigned';
            }
        } elseif ($event->staffId) {
            $targets[$event->staffId] = 'current';
        }

        foreach ($targets as $staffId => $assignment) {
            $staff = Staff::query()->with('user')->find($staffId);
            $user = $staff?->user;
            if (!$user || empty($user->password) || $user->notifications_enabled === false) {
                continue; // no app account or opted out
            }
            $user->notify(new StaffAppointmentLifecycle(
                $event->type, $assignment, $appointment->id, $event->bookingGroupId,
                $serviceLabel, $date, $time,
            ));
        }
    }
}
