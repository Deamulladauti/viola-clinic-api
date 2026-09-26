<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Database notification for one visit (single appointment or joined group). */
final class ClientAppointmentLifecycle extends Notification
{
    public function __construct(
        private readonly string $eventType,
        private readonly int $appointmentId,
        private readonly ?int $bookingGroupId,
        private readonly string $serviceLabel,
        private readonly string $date,
        private readonly string $time,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $lang = in_array($notifiable->preferred_language, ['en', 'sq', 'mk'], true)
            ? $notifiable->preferred_language : 'en';
        $labels = [
            'en' => [
                'appointment.created' => ['Booking confirmed', 'Your booking for %s is scheduled for %s at %s.'],
                'appointment.rescheduled' => ['Booking rescheduled', 'Your booking for %s is now scheduled for %s at %s.'],
                'appointment.cancelled' => ['Booking cancelled', 'Your booking for %s on %s at %s was cancelled.'],
                'appointment.reminder_due' => ['Appointment reminder', 'Reminder: %s is scheduled for %s at %s.'],
            ],
            'sq' => [
                'appointment.created' => ['Rezervimi u konfirmua', 'Rezervimi juaj për %s është më %s në %s.'],
                'appointment.rescheduled' => ['Rezervimi u ricaktua', 'Rezervimi juaj për %s tani është më %s në %s.'],
                'appointment.cancelled' => ['Rezervimi u anulua', 'Rezervimi juaj për %s më %s në %s u anulua.'],
                'appointment.reminder_due' => ['Kujtesë për termin', 'Kujtesë: %s është më %s në %s.'],
            ],
            'mk' => [
                'appointment.created' => ['Терминот е потврден', 'Вашиот термин за %s е закажан за %s во %s.'],
                'appointment.rescheduled' => ['Терминот е презакажан', 'Вашиот термин за %s сега е закажан за %s во %s.'],
                'appointment.cancelled' => ['Терминот е откажан', 'Вашиот термин за %s на %s во %s е откажан.'],
                'appointment.reminder_due' => ['Потсетник за термин', 'Потсетник: %s е закажан за %s во %s.'],
            ],
        ];
        [$title, $template] = $labels[$lang][$this->eventType] ?? $labels['en'][$this->eventType];

        return [
            'type' => $this->eventType,
            'appointment_id' => $this->appointmentId,
            'booking_group_id' => $this->bookingGroupId,
            'title' => $title,
            'body' => sprintf($template, $this->serviceLabel, $this->date, substr($this->time, 0, 5)),
        ];
    }
}
