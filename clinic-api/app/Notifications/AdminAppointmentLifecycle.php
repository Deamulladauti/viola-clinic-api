<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Database-only Admin alert; push and notification-center UI are later tasks. */
final class AdminAppointmentLifecycle extends Notification
{
    public function __construct(
        private readonly string $eventType,
        private readonly int $appointmentId,
        private readonly ?int $bookingGroupId,
        private readonly string $clientName,
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
                'appointment.created' => ['Client booking received', '%s booked %s for %s at %s.'],
                'appointment.rescheduled' => ['Client rescheduled booking', '%s rescheduled %s to %s at %s.'],
                'appointment.cancelled' => ['Client cancelled booking', '%s cancelled %s on %s at %s.'],
            ],
            'sq' => [
                'appointment.created' => ['Rezervim i ri nga klienti', '%s rezervoi %s për %s në %s.'],
                'appointment.rescheduled' => ['Klienti ndryshoi terminin', '%s e ricaktoi %s për %s në %s.'],
                'appointment.cancelled' => ['Klienti anuloi terminin', '%s anuloi %s më %s në %s.'],
            ],
            'mk' => [
                'appointment.created' => ['Нов термин од клиент', '%s закажа %s за %s во %s.'],
                'appointment.rescheduled' => ['Клиентот го презакажа терминот', '%s го презакажа %s за %s во %s.'],
                'appointment.cancelled' => ['Клиентот го откажа терминот', '%s го откажа %s на %s во %s.'],
            ],
        ];
        [$title, $template] = $labels[$lang][$this->eventType] ?? $labels['en'][$this->eventType] ?? ['Booking update', '%s: %s, %s %s'];

        return [
            'type' => $this->eventType,
            'appointment_id' => $this->appointmentId,
            'booking_group_id' => $this->bookingGroupId,
            'title' => $title,
            'body' => sprintf($template, $this->clientName, $this->serviceLabel, $this->date, substr($this->time, 0, 5)),
        ];
    }
}
