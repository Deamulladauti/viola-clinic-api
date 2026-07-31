<?php

namespace App\Listeners;

use App\Events\AppointmentBookedEvent;
use App\Notifications\AppointmentBooked;

class SendNotificationOnAppointmentBooked
{
    public function handle(AppointmentBookedEvent $event): void
    {
        $client = $event->appointment->client; // adjust relation name if different
        if ($client) {
            $client->notify(new AppointmentBooked($event->appointment));
        }
    }
}
