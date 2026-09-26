<?php

namespace App\Listeners;

use App\Events\ClinicNotificationEvent;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AdminAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;

/** Operational alerts only for actions initiated by a client, not by Admin. */
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

        $appointment = Appointment::query()->with(['service', 'user'])->find($event->appointmentId);
        if (!$appointment) {
            return;
        }

        // A client-created appointment retains this source even when Admin later edits it.
        // For updates, the authenticated actor MUST be a client; source alone is insufficient.
        if ($event->type === ClinicNotificationEvents::CREATED) {
            if ($appointment->source !== Appointment::SOURCE_CLIENT_BOOKING) {
                return;
            }
        } elseif ($event->origin !== 'client_action') {
            return;
        }

        $appointments = $event->bookingGroupId
            ? Appointment::query()->where('booking_group_id', $event->bookingGroupId)
                ->orderBy('id')->with('service')->get()
            : collect([$appointment]);
        if ($appointments->isEmpty() || (int) $appointments->first()->id !== $event->appointmentId) {
            return;
        }

        $label = $appointments->map(fn ($a) => $a->service?->name ?? 'Treatment')
            ->unique()->implode(' + ');
        $date = $appointment->date?->format('Y-m-d') ?? '';
        $clientName = $appointment->user?->name ?: 'Client';

        User::role('admin')->whereNotNull('password')->get()->each(function (User $admin) use ($event, $appointment, $clientName, $label, $date): void {
            if ($admin->notifications_enabled === false) {
                return;
            }
            $admin->notify(new AdminAppointmentLifecycle(
                $event->type, $appointment->id, $event->bookingGroupId,
                $clientName, $label, $date, (string) $appointment->starts_at,
            ));
        });
    }
}
