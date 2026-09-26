<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\ClinicNotificationEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAppointmentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $tz = config('clinic.timezone', config('app.timezone', 'Europe/Skopje'));
        $targetDate = now($tz)->addDay()->toDateString();

        $appointments = Appointment::query()
            ->where('status', 'confirmed')
            ->whereDate('date', $targetDate)
            ->with(['client', 'service'])
            ->get();

        $events = app(ClinicNotificationEvents::class);
        foreach ($appointments as $a) {
            if ($a->booking_group_id) {
                $firstId = Appointment::query()->where('booking_group_id', $a->booking_group_id)->min('id');
                if ((int) $firstId !== (int) $a->id) {
                    continue;
                }
            }
            if (!$a->client || empty($a->client->password) || $a->client->notifications_enabled === false) {
                continue;
            }
            $alreadySent = $a->client->notifications()
                ->where('data->type', ClinicNotificationEvents::REMINDER_DUE)
                ->where('data->appointment_id', $a->id)
                ->whereDate('created_at', now($tz)->toDateString())
                ->exists();
            if (!$alreadySent) {
                $events->emit(ClinicNotificationEvents::REMINDER_DUE, $a, 'reminder_job');
            }
        }
    }
}

