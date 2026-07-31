<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

class AppointmentReminder extends Notification implements ShouldQueue
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

        return [
            'type'           => 'appointment_reminder',
            'appointment_id' => $this->appointment->id,
            'title'          => __('notifications.reminder.title'),
            'body'           => __('notifications.reminder.body', [
                'service' => $this->appointment->service->name,
                'date'    => $this->appointment->date,
                'time'    => $this->appointment->starts_at,
            ]),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $lang = $notifiable->pref_lang ?? 'en';
        app()->setLocale($lang);

        return (new MailMessage)
            ->subject(__('notifications.reminder.mail_subject'))
            ->greeting(__('notifications.reminder.title'))
            ->line(__('notifications.reminder.body', [
                'service' => $this->appointment->service->name,
                'date'    => $this->appointment->date,
                'time'    => $this->appointment->starts_at,
            ]));
    }
}
