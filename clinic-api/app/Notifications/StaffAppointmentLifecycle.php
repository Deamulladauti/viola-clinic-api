<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** One database notification per staff member per visit event. */
final class StaffAppointmentLifecycle extends Notification
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $assignment,
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
                'appointment.created' => ['New assigned booking', '%s is booked for %s at %s.'],
                'appointment.rescheduled' => ['Booking rescheduled', '%s is now scheduled for %s at %s.'],
                'appointment.cancelled' => ['Booking cancelled', '%s on %s at %s was cancelled.'],
                'assigned' => ['Booking assigned to you', '%s is assigned to you for %s at %s.'],
                'removed' => ['Booking reassigned', '%s on %s at %s is no longer assigned to you.'],
            ],
            'sq' => [
                'appointment.created' => ['Rezervim i ri', '%s është rezervuar më %s në %s.'],
                'appointment.rescheduled' => ['Termini u ricaktua', '%s tani është më %s në %s.'],
                'appointment.cancelled' => ['Termini u anulua', '%s më %s në %s u anulua.'],
                'assigned' => ['Termini ju është caktuar', '%s ju është caktuar më %s në %s.'],
                'removed' => ['Termini u ricaktua', '%s më %s në %s nuk ju është më i caktuar.'],
            ],
            'mk' => [
                'appointment.created' => ['Нов доделен термин', '%s е закажан за %s во %s.'],
                'appointment.rescheduled' => ['Терминот е презакажан', '%s сега е закажан за %s во %s.'],
                'appointment.cancelled' => ['Терминот е откажан', '%s на %s во %s е откажан.'],
                'assigned' => ['Доделен ви е термин', '%s ви е доделен за %s во %s.'],
                'removed' => ['Терминот е прераспределен', '%s на %s во %s повеќе не ви е доделен.'],
            ],
        ];
        $key = $this->eventType === 'appointment.staff_reassigned' ? $this->assignment : $this->eventType;
        [$title, $template] = $labels[$lang][$key] ?? $labels['en'][$key];

        return [
            'type' => $this->eventType,
            'assignment' => $this->assignment,
            'appointment_id' => $this->appointmentId,
            'booking_group_id' => $this->bookingGroupId,
            'title' => $title,
            'body' => sprintf($template, $this->serviceLabel, $this->date, substr($this->time, 0, 5)),
        ];
    }
}
