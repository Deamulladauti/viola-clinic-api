<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use App\Models\Appointment;

class AvailabilityController extends Controller
{
    /**
     * GET /api/v1/availability?service_id=&staff_id=&date=YYYY-MM-DD[&step=15]
     * Returns a list of start times (HH:MM) where the full service duration fits
     * within staff working hours, not during time-off, and not overlapping other appointments.
     */
    public function day(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required','integer','exists:services,id'],
            'staff_id'   => ['required','integer','exists:staff,id'],
            'date'       => ['required','date_format:Y-m-d'],
            'step'       => ['nullable','integer','in:5,10,15,20,30,60'],
        ]);

        $tz       = config('app.timezone');
        $service  = Service::findOrFail((int)$data['service_id']);
        $staff    = Staff::findOrFail((int)$data['staff_id']);
        $date     = $data['date'];
        $step     = (int)($data['step'] ?? 15);
        $duration = (int)($service->duration_minutes ?? 30);

        // Optional: if you enforce staff↔service relationship, block if staff can't perform this service.
        if (method_exists($service, 'staff') && $service->staff()->where('staff.id', $staff->id)->doesntExist()) {
            return response()->json(['message' => 'Staff does not perform this service'], 422);
        }

        // 1) Working hours (weekday 0..6, Sunday=0 matches your migration)
        $weekday = Carbon::parse($date, $tz)->dayOfWeek; // 0..6
        $sched = StaffSchedule::where('staff_id', $staff->id)
            ->where('weekday', $weekday)
            ->first();

        if (!$sched || !$sched->is_active) {
            return response()->json([
                'date' => $date,
                'service' => ['id'=>$service->id,'name'=>$service->name,'duration_minutes'=>$duration],
                'staff' => ['id'=>$staff->id,'name'=>$staff->name],
                'available_slots' => [],
                'timezone' => $tz,
                'generated_at' => Carbon::now($tz)->toIso8601String(),
            ]);
        }

        $dayStart = Carbon::parse("{$date} {$sched->start_time}", $tz);
        $dayEnd   = Carbon::parse("{$date} {$sched->end_time}",   $tz);
        if ($dayEnd->lte($dayStart)) {
            return response()->json([
                'date' => $date,
                'service' => ['id'=>$service->id,'name'=>$service->name,'duration_minutes'=>$duration],
                'staff' => ['id'=>$staff->id,'name'=>$staff->name],
                'available_slots' => [],
                'timezone' => $tz,
                'generated_at' => Carbon::now($tz)->toIso8601String(),
            ]);
        }

        // 2) Time-off on that date (full-day or partial ranges)
        $timeOff = StaffTimeOff::where('staff_id', $staff->id)
            ->whereDate('date', $date)
            ->get();

        // 3) Existing appointments (same staff OR same service) that block the window
        $blocks = Appointment::query()
            ->whereDate('date', $date)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->where(function ($q) use ($service, $staff) {
                $q->where('staff_id', $staff->id)
                  ->orWhere('service_id', $service->id);
            })
            ->get()
            ->map(function (Appointment $a) use ($tz) {
                $st = strlen($a->starts_at) === 5 ? $a->starts_at . ':00' : $a->starts_at;
                $s  = Carbon::createFromFormat('Y-m-d H:i:s', "{$a->date} {$st}", $tz);
                $e  = (clone $s)->addMinutes((int)$a->duration_minutes);
                return [$s, $e];
            });

        // 4) Build candidate slots every step minutes inside working hours
        $slots = [];
        $cursor = $dayStart->copy();

        // If requesting today's date, optionally hide past times (UX nicety)
        $todayLocal = Carbon::now($tz)->toDateString();
        $hidePast   = $date === $todayLocal;

        while ($cursor->lte($dayEnd)) {
            $slotStart = $cursor->copy();
            $slotEnd   = $cursor->copy()->addMinutes($duration);

            // Entire service must fit inside day window
            if ($slotEnd->gt($dayEnd)) {
                break;
            }

            // Skip past times if today
            if ($hidePast && $slotStart->lte(Carbon::now($tz))) {
                $cursor->addMinutes($step);
                continue;
            }

            // Time-off check
            $blockedByTO = $timeOff->contains(function (StaffTimeOff $to) use ($tz, $date, $slotStart, $slotEnd) {
                if (!$to->start_time && !$to->end_time) {
                    return true; // full-day off
                }
                $toStart = Carbon::parse("{$date} " . ($to->start_time ?? '00:00:00'), $tz);
                $toEnd   = Carbon::parse("{$date} " . ($to->end_time   ?? '23:59:59'), $tz);
                return $slotStart->lt($toEnd) && $slotEnd->gt($toStart);
            });

            if ($blockedByTO) {
                $cursor->addMinutes($step);
                continue;
            }

            // Overlap check with appointments
            $overlap = $blocks->contains(function ($pair) use ($slotStart, $slotEnd) {
                [$bStart, $bEnd] = $pair;
                return $slotStart->lt($bEnd) && $slotEnd->gt($bStart);
            });

            if (!$overlap) {
                $slots[] = $slotStart->format('H:i');
            }

            $cursor->addMinutes($step);
        }

        return response()->json([
            'date' => $date,
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $duration,
            ],
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
            ],
            'available_slots' => $slots,
            'step_minutes' => $step,
            'timezone' => $tz,
            'generated_at' => Carbon::now($tz)->toIso8601String(),
        ]);
    }
}
