<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
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

        foreach ($appointments as $a) {
            if ($a->client) {
                $a->client->notify(new AppointmentReminder($a));
            }
        }
    }
}

