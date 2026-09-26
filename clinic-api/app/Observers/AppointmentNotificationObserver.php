<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\ClinicNotificationEvents;
use Illuminate\Support\Facades\DB;

/** Route-independent notification hooks: Admin, Staff, and Client mutations. */
final class AppointmentNotificationObserver
{
    public function created(Appointment $appointment): void
    {
        if ($appointment->source === Appointment::SOURCE_MANUAL_IMPORT || $appointment->source === Appointment::SOURCE_LEGACY) {
            return;
        }
        // Group ID may be assigned after create() inside the booking transaction.
        // Reload after commit so a joined visit produces ONE combined notification.
        $id = (int) $appointment->getKey();
        DB::afterCommit(static function () use ($id): void {
            $saved = Appointment::query()->find($id);
            if ($saved) {
                app(ClinicNotificationEvents::class)->emit(ClinicNotificationEvents::CREATED, $saved, 'appointment_model');
            }
        });
    }

    public function updated(Appointment $appointment): void
    {
        $events = app(ClinicNotificationEvents::class);
        $origin = auth()->user()?->hasRole('client') ? 'client_action' : 'appointment_model';
        if ($appointment->wasChanged('status') && $appointment->status === Appointment::STATUS_CANCELLED) {
            $events->emit(ClinicNotificationEvents::CANCELLED, $appointment, $origin, [], null, (string) $appointment->getOriginal('status'));
            return;
        }
        if ($appointment->wasChanged('staff_id')) {
            $previousStaffId = $appointment->getOriginal('staff_id');
            $events->emit(
                ClinicNotificationEvents::STAFF_REASSIGNED,
                $appointment,
                $origin,
                ['staff_id' => ['from' => $previousStaffId, 'to' => $appointment->staff_id]],
                $previousStaffId !== null ? (int) $previousStaffId : null,
            );
        }
        if ($appointment->wasChanged(['date', 'starts_at'])) {
            $changes = [];
            foreach (['date', 'starts_at'] as $field) {
                if ($appointment->wasChanged($field)) {
                    $changes[$field] = ['from' => (string) $appointment->getOriginal($field), 'to' => (string) $appointment->{$field}];
                }
            }
            $events->emit(ClinicNotificationEvents::RESCHEDULED, $appointment, $origin, $changes);
        }
    }
}
