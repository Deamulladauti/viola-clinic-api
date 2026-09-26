<?php

namespace App\Listeners;

use App\Events\ClinicNotificationEvent;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\ClientAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;

final class SendClientClinicNotification
{
    public function handle(ClinicNotificationEvent $event): void
    {
        if (!in_array($event->type, [
            ClinicNotificationEvents::CREATED, ClinicNotificationEvents::RESCHEDULED,
            ClinicNotificationEvents::CANCELLED, ClinicNotificationEvents::REMINDER_DUE,
        ], true)) {
            return;
        }

        $appointment = Appointment::query()->find($event->appointmentId);
        if (!$appointment || !$event->clientId) {
            return;
        }
        $client = User::query()->find($event->clientId);
        // Admin-created legacy records do not have a mobile login yet.
        if (!$client || empty($client->password) || $client->notifications_enabled === false) {
            return;
        }

        $appointments = $event->bookingGroupId
            ? Appointment::query()->where('booking_group_id', $event->bookingGroupId)->orderBy('id')->with('service')->get()
            : collect([$appointment->loadMissing('service')]);
        if ($appointments->isEmpty() || (int) $appointments->first()->id !== $event->appointmentId) {
            return; // one notification per joined visit, not one per treatment
        }
        $label = $appointments->map(fn ($a) => $a->service?->name ?? 'Treatment')->unique()->implode(' + ');
        $date = $appointment->date?->format('Y-m-d') ?? '';
        $client->notify(new ClientAppointmentLifecycle(
            $event->type, $appointment->id, $event->bookingGroupId,
            $label, $date, (string) $appointment->starts_at,
        ));
    }
}
