<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AppointmentBookingValidator
{
    public function validateStaffQualification(Service $service, Staff $staff): void
    {
        if (!$staff->is_active) {
            throw ValidationException::withMessages([
                'staff_id' => 'The selected staff member is not active.',
            ]);
        }

        if ($service->staff()->whereKey($staff->id)->doesntExist()) {
            throw ValidationException::withMessages([
                'staff_id' => 'The selected staff member is not qualified for this service.',
            ]);
        }
    }

    public function validateScheduledSlot(
        Service $service,
        Staff $staff,
        string $date,
        string $startsAt,
        ?int $ignoreAppointmentId = null,
    ): void {
        $timezone = config('clinic.timezone', config('app.timezone'));
        $duration = max(1, (int) ($service->duration_minutes ?? 60));
        $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$startsAt}", $timezone);
        $end = $start->copy()->addMinutes($duration);
        $weekday = $start->dayOfWeek;

        $schedule = StaffSchedule::query()
            ->where('staff_id', $staff->id)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->first();

        if (!$schedule) {
            throw ValidationException::withMessages([
                'starts_at' => 'The selected staff member is not scheduled to work on this date.',
            ]);
        }

        $scheduleStart = Carbon::parse("{$date} {$schedule->start_time}", $timezone);
        $scheduleEnd = Carbon::parse("{$date} {$schedule->end_time}", $timezone);

        if ($start->lt($scheduleStart) || $end->gt($scheduleEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => 'The appointment must fit completely inside the staff working hours.',
            ]);
        }

        $timeOffItems = StaffTimeOff::query()
            ->where('staff_id', $staff->id)
            ->whereDate('date', $date)
            ->get();

        foreach ($timeOffItems as $timeOff) {
            if (!$timeOff->start_time && !$timeOff->end_time) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The selected staff member is unavailable on this date.',
                ]);
            }

            $timeOffStart = Carbon::parse("{$date} " . ($timeOff->start_time ?? '00:00:00'), $timezone);
            $timeOffEnd = Carbon::parse("{$date} " . ($timeOff->end_time ?? '23:59:59'), $timezone);

            if ($start->lt($timeOffEnd) && $end->gt($timeOffStart)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The selected time overlaps staff time off.',
                ]);
            }
        }

        $appointments = Appointment::query()
            ->whereDate('date', $date)
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_COMPLETED,
            ])
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->where(function ($query) use ($staff, $service) {
                $query->where('staff_id', $staff->id)
                    ->orWhere('service_id', $service->id);
            })
            ->get(['id', 'date', 'starts_at', 'duration_minutes']);

        foreach ($appointments as $appointment) {
            $existingStart = Carbon::parse(
                $appointment->date->toDateString() . ' ' . $appointment->starts_at,
                $timezone,
            );
            $existingEnd = $existingStart->copy()->addMinutes((int) $appointment->duration_minutes);

            if ($start->lt($existingEnd) && $end->gt($existingStart)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The selected time overlaps an existing appointment.',
                ]);
            }
        }
    }
}
