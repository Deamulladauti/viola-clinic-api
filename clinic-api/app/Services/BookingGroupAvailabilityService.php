<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BookingGroupAvailabilityService
{
    /**
     * Return start times where every treatment can run sequentially as one
     * continuous visit for the selected staff member.
     *
     * The current application does not yet have explicit room/resource models.
     * To stay compatible with the existing AppointmentBookingValidator, the
     * resource guard here treats an overlapping appointment for the same
     * service as a resource conflict. When explicit rooms/resources are added,
     * this is the single service to extend with capacity-aware checks.
     *
     * @param  array<int, int>  $serviceIds
     * @return array{
     *   date:string,
     *   staff:array{id:int,name:string},
     *   treatments:array<int,array{service_id:int,name:string,duration_minutes:int,offset_minutes:int}>,
     *   total_duration_minutes:int,
     *   available_slots:array<int,string>,
     *   step_minutes:int,
     *   resource_guard:string
     * }
     */
    public function availableSlots(array $serviceIds, int $staffId, string $date, int $step = 15): array
    {
        $context = $this->buildContext($serviceIds, $staffId, $date);
        $slots = [];

        if (!$context['schedule']) {
            return $this->result($context, $date, $step, []);
        }

        $timezone = $context['timezone'];
        $schedule = $context['schedule'];
        $dayStart = Carbon::parse("{$date} {$schedule->start_time}", $timezone);
        $dayEnd = Carbon::parse("{$date} {$schedule->end_time}", $timezone);

        if ($dayEnd->lte($dayStart)) {
            return $this->result($context, $date, $step, []);
        }

        $cursor = $dayStart->copy();
        $today = Carbon::now($timezone)->toDateString();
        $hidePast = $date === $today;

        while ($cursor->lte($dayEnd)) {
            $start = $cursor->copy();
            $end = $start->copy()->addMinutes($context['total_duration_minutes']);

            if ($end->gt($dayEnd)) {
                break;
            }

            if ($hidePast && $start->lte(Carbon::now($timezone))) {
                $cursor->addMinutes($step);
                continue;
            }

            if ($this->firstConflict($context, $start) === null) {
                $slots[] = $start->format('H:i');
            }

            $cursor->addMinutes($step);
        }

        return $this->result($context, $date, $step, $slots);
    }

    /**
     * Validate one exact joined-visit start time before any group/appointment
     * records are written.
     *
     * @param array<int, int> $serviceIds
     */
    public function validateBlock(array $serviceIds, int $staffId, string $date, string $startsAt): void
    {
        $context = $this->buildContext($serviceIds, $staffId, $date);
        $timezone = $context['timezone'];

        if (!$context['schedule']) {
            throw ValidationException::withMessages([
                'starts_at' => 'The selected staff member is not scheduled to work on this date.',
            ]);
        }

        $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$startsAt}", $timezone);
        $conflict = $this->firstConflict($context, $start);

        if ($conflict === null) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_at' => $conflict['message'],
        ]);
    }

    /**
     * @param array<int, int> $serviceIds
     * @return array<string,mixed>
     */
    private function buildContext(array $serviceIds, int $staffId, string $date): array
    {
        $serviceIds = array_values(array_map('intval', $serviceIds));
        if (count($serviceIds) < 2) {
            throw ValidationException::withMessages([
                'service_ids' => 'A joined booking requires at least two treatments.',
            ]);
        }

        $servicesById = Service::query()
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        $services = collect($serviceIds)->map(function (int $serviceId) use ($servicesById) {
            /** @var Service|null $service */
            $service = $servicesById->get($serviceId);
            if (!$service) {
                throw ValidationException::withMessages([
                    'service_ids' => "Service {$serviceId} could not be found.",
                ]);
            }

            $usageType = (string) ($service->usage_type ?? ($service->is_package ? 'session' : 'single'));
            $requiresAppointment = (bool) ($service->requires_appointment ?? $service->is_bookable);

            if (!$service->is_active || !$requiresAppointment || $usageType === 'minutes') {
                throw ValidationException::withMessages([
                    'service_ids' => "{$service->name} cannot be scheduled as an appointment.",
                ]);
            }

            return $service;
        });

        /** @var Staff $staff */
        $staff = Staff::query()->findOrFail($staffId);
        if (!$staff->is_active) {
            throw ValidationException::withMessages([
                'staff_id' => 'The selected staff member is not active.',
            ]);
        }

        $timezone = config('clinic.timezone', config('app.timezone'));
        $day = Carbon::createFromFormat('Y-m-d', $date, $timezone);
        $schedule = StaffSchedule::query()
            ->where('staff_id', $staff->id)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->first();

        $timeOff = StaffTimeOff::query()
            ->where('staff_id', $staff->id)
            ->whereDate('date', $date)
            ->get();

        $appointments = Appointment::query()
            ->whereDate('date', $date)
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_COMPLETED,
            ])
            ->where(function ($query) use ($staff, $serviceIds) {
                $query->where('staff_id', $staff->id)
                    ->orWhereIn('service_id', $serviceIds);
            })
            ->get(['id', 'service_id', 'staff_id', 'date', 'starts_at', 'duration_minutes']);

        $offset = 0;
        $segments = [];
        foreach ($services as $service) {
            $duration = max(1, (int) ($service->duration_minutes ?? 60));
            $segments[] = [
                'service' => $service,
                'offset_minutes' => $offset,
                'duration_minutes' => $duration,
            ];
            $offset += $duration;
        }

        return [
            'timezone' => $timezone,
            'staff' => $staff,
            'schedule' => $schedule,
            'time_off' => $timeOff,
            'appointments' => $appointments,
            'segments' => $segments,
            'total_duration_minutes' => $offset,
        ];
    }

    /** @return array{type:string,message:string}|null */
    private function firstConflict(array $context, Carbon $start): ?array
    {
        $timezone = $context['timezone'];
        /** @var StaffSchedule|null $schedule */
        $schedule = $context['schedule'];
        /** @var Staff $staff */
        $staff = $context['staff'];
        /** @var Collection<int,StaffTimeOff> $timeOff */
        $timeOff = $context['time_off'];
        /** @var Collection<int,Appointment> $appointments */
        $appointments = $context['appointments'];

        if (!$schedule) {
            return [
                'type' => 'schedule',
                'message' => 'The selected staff member is not scheduled to work on this date.',
            ];
        }

        $date = $start->toDateString();
        $end = $start->copy()->addMinutes($context['total_duration_minutes']);
        $scheduleStart = Carbon::parse("{$date} {$schedule->start_time}", $timezone);
        $scheduleEnd = Carbon::parse("{$date} {$schedule->end_time}", $timezone);

        if ($start->lt($scheduleStart) || $end->gt($scheduleEnd)) {
            return [
                'type' => 'schedule',
                'message' => 'The complete joined visit must fit inside the staff working hours.',
            ];
        }

        foreach ($timeOff as $item) {
            if (!$item->start_time && !$item->end_time) {
                return [
                    'type' => 'time_off',
                    'message' => 'The selected staff member is unavailable on this date.',
                ];
            }

            $offStart = Carbon::parse("{$date} ".($item->start_time ?? '00:00:00'), $timezone);
            $offEnd = Carbon::parse("{$date} ".($item->end_time ?? '23:59:59'), $timezone);

            if ($this->overlaps($start, $end, $offStart, $offEnd)) {
                return [
                    'type' => 'time_off',
                    'message' => 'The complete joined visit overlaps staff time off.',
                ];
            }
        }

        // Reserve the staff member for one continuous block, not merely each
        // treatment independently.
        foreach ($appointments as $appointment) {
            if ((int) $appointment->staff_id !== (int) $staff->id) {
                continue;
            }

            [$existingStart, $existingEnd] = $this->appointmentWindow($appointment, $timezone);
            if ($this->overlaps($start, $end, $existingStart, $existingEnd)) {
                return [
                    'type' => 'staff',
                    'message' => 'The complete joined visit overlaps another appointment for this staff member.',
                ];
            }
        }

        // Preserve the application's existing service-level resource guard for
        // each treatment segment. This catches an overlapping use of the same
        // treatment/resource even when it belongs to a different staff member.
        foreach ($context['segments'] as $segment) {
            /** @var Service $service */
            $service = $segment['service'];
            $segmentStart = $start->copy()->addMinutes($segment['offset_minutes']);
            $segmentEnd = $segmentStart->copy()->addMinutes($segment['duration_minutes']);

            foreach ($appointments as $appointment) {
                if ((int) $appointment->service_id !== (int) $service->id) {
                    continue;
                }

                [$existingStart, $existingEnd] = $this->appointmentWindow($appointment, $timezone);
                if ($this->overlaps($segmentStart, $segmentEnd, $existingStart, $existingEnd)) {
                    return [
                        'type' => 'resource',
                        'message' => "{$service->name} overlaps an existing appointment using the same service/resource.",
                    ];
                }
            }
        }

        return null;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function appointmentWindow(Appointment $appointment, string $timezone): array
    {
        $date = $appointment->date instanceof Carbon
            ? $appointment->date->toDateString()
            : Carbon::parse($appointment->date, $timezone)->toDateString();
        $time = substr((string) $appointment->starts_at, 0, 5);
        $start = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone);
        $end = $start->copy()->addMinutes(max(1, (int) $appointment->duration_minutes));

        return [$start, $end];
    }

    private function overlaps(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lt($bEnd) && $aEnd->gt($bStart);
    }

    /** @return array<string,mixed> */
    private function result(array $context, string $date, int $step, array $slots): array
    {
        return [
            'date' => $date,
            'staff' => [
                'id' => (int) $context['staff']->id,
                'name' => (string) $context['staff']->name,
            ],
            'treatments' => collect($context['segments'])->map(fn (array $segment) => [
                'service_id' => (int) $segment['service']->id,
                'name' => (string) $segment['service']->name,
                'duration_minutes' => (int) $segment['duration_minutes'],
                'offset_minutes' => (int) $segment['offset_minutes'],
            ])->values()->all(),
            'total_duration_minutes' => (int) $context['total_duration_minutes'],
            'available_slots' => array_values($slots),
            'step_minutes' => $step,
            'resource_guard' => 'service_level',
        ];
    }
}
