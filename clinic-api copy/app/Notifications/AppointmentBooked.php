<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

class AppointmentBooked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public $appointment) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        $lang = $notifiable->preferred_language ?? 'en';
        App::setLocale($lang);

        $date = Carbon::parse($this->appointment->date);
        $time = Carbon::parse($this->appointment->starts_at);

        return [
            'type'          => 'appointment_booked',
            'appointment_id'=> $this->appointment->id,
            'title'         => __('notifications.booked.title'),
            'body'          => __('notifications.booked.body', [
                'service' => $this->appointment->service->name,
                'date'    => $date->translatedFormat('jS F Y'),
                'time'    => $time->format('H:i'),
            ]),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $lang = $notifiable->pref_lang ?? 'en';
        app()->setLocale($lang);

        $date = Carbon::parse($this->appointment->date);
        $time = Carbon::parse($this->appointment->starts_at);

        return (new MailMessage)
            ->subject(__('notifications.booked.mail_subject'))
            ->greeting(__('notifications.booked.title'))
            ->line(__('notifications.booked.body', [
                'service' => $this->appointment->service->name,
                'date'    => $date->translatedFormat('jS F Y'),
                'time'    => $time->format('H:i'),
            ]));
    }
}
