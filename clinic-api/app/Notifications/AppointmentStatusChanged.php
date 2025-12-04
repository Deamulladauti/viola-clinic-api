<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

class AppointmentStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public $appointment, public string $oldStatus) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        $lang = $notifiable->preferred_language ?? 'en';
        App::setLocale($lang);

        return [
            'type'           => 'appointment_status_changed',
            'appointment_id' => $this->appointment->id,
            'title'          => __('notifications.status.title'),
            'body'           => __('notifications.status.body', [
                'service' => $this->appointment->service->name,
                'date'    => $this->appointment->date,
                'time'    => $this->appointment->starts_at,
                'status'  => $this->appointment->status,
            ]),
            'old_status'     => $this->oldStatus,
            'new_status'     => $this->appointment->status,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $lang = $notifiable->pref_lang ?? 'en';
        app()->setLocale($lang);

        return (new MailMessage)
            ->subject(__('notifications.status.mail_subject'))
            ->greeting(__('notifications.status.title'))
            ->line(__('notifications.status.body', [
                'service' => $this->appointment->service->name,
                'date'    => $this->appointment->date,
                'time'    => $this->appointment->starts_at,
                'status'  => $this->appointment->status,
            ]));
    }
}
