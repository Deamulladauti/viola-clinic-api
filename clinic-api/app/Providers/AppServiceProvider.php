<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Appointment::observe(\App\Observers\AppointmentNotificationObserver::class);
        // Send a push only after a database notification exists; preserve the existing
        // per-role, per-booking deduplication and translated notification text.
        \Illuminate\Notifications\DatabaseNotification::created(function ($notification) {
            if ($notification->notifiable_type !== \App\Models\User::class) return;
            \App\Jobs\SendExpoNotification::dispatch(
                (int) $notification->notifiable_id, (string) $notification->id
            )->afterCommit();
        });
    }
}
