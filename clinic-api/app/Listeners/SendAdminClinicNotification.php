<?php

namespace App\Listeners;

use App\Events\ClinicNotificationEvent;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AdminAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;

/** Notify Admin about client- and staff-initiated booking activity. */
final class SendAdminClinicNotification
{
    public function handle(ClinicNotificationEvent $event): void
    {
        if (!in_array($event->type, [
            ClinicNotificationEvents::CREATED,
            ClinicNotificationEvents::RESCHEDULED,
            ClinicNotificationEvents::CANCELLED,
        ], true)) {
            return;
        }

        // Admin should not receive alerts about their own actions.
        if (!in_array($event->origin, [
            'client_action',
            'staff_action',
        ], true)) {
            return;
        }

        $appointment = Appointment::query()
            ->with(['service', 'user'])
            ->find($event->appointmentId);

        if (!$appointment) {
            return;
        }

        // Preserve the existing client-booking source restriction.
        if (
            $event->type === ClinicNotificationEvents::CREATED &&
            $event->origin === 'client_action' &&
            $appointment->source !== Appointment::SOURCE_CLIENT_BOOKING
        ) {
            return;
        }

        $appointments = $event->bookingGroupId
            ? Appointment::query()
                ->where('booking_group_id', $event->bookingGroupId)
                ->orderBy('id')
                ->with('service')
                ->get()
            : collect([$appointment]);

        if (
            $appointments->isEmpty() ||
            (int) $appointments->first()->id !== $event->appointmentId
        ) {
            return;
        }

        $label = $appointments
            ->map(fn ($a) => $a->service?->name ?? 'Treatment')
            ->unique()
            ->implode(' + ');

        $date = $appointment->date?->format('Y-m-d') ?? '';
        $clientName = $appointment->user?->name ?: 'Client';

        User::role('admin')
            ->whereNotNull('password')
            ->get()
            ->each(function (User $admin) use (
                $event,
                $appointment,
                $clientName,
                $label,
                $date
            ): void {
                if ($admin->notifications_enabled === false) {
                    return;
                }

                $admin->notify(new AdminAppointmentLifecycle(
                    $event->type,
                    $appointment->id,
                    $event->bookingGroupId,
                    $clientName,
                    $label,
                    $date,
                    (string) $appointment->starts_at,
                ));
            });
    }
}
