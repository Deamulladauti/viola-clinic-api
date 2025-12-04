<?php

namespace App\Listeners;

use App\Events\AppointmentStatusChangedEvent;
use App\Notifications\AppointmentStatusChanged;

class SendNotificationOnAppointmentStatusChanged
{
    public function handle(AppointmentStatusChangedEvent $event): void
    {
        $client = $event->appointment->client;
        if ($client) {
            $client->notify(new AppointmentStatusChanged($event->appointment, $event->oldStatus));
        }
    }
}
