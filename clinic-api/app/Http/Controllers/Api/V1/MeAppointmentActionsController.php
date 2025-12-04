<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Appointment;
use App\Models\Staff;

class MeAppointmentActionsController extends Controller
{
    /** Client cancels (deletes) their appointment */
    public function cancel(Request $request, int $id)
    {
        $user  = $request->user();
        $email = strtolower(trim($user->email));

        $appt = Appointment::with(['service','staff'])
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->findOrFail($id);

        // Business rules
        if (!in_array($appt->status, ['pending','confirmed'])) {
            return response()->json(['message' => 'Only pending/confirmed appointments can be cancelled.'], 422);
        }

        $now   = Carbon::now();
        $start = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($appt->date)->toDateString().' '.$appt->starts_at);
        $minNotice = (int) config('clinic.min_notice_minutes', 120);

        if ($start->lt($now)) {
            return response()->json(['message' => 'Cannot cancel past appointments.'], 422);
        }
        if ($start->diffInMinutes($now, false) > -$minNotice) {
            return response()->json(['message' => "Please cancel at least {$minNotice} minutes in advance."], 422);
        }

        // Preferred: mark as cancelled (keeps audit trail)
        $appt->status = 'cancelled';
        $appt->save();

        // If you really want to delete instead (soft delete), uncomment:
        // $appt->delete();

        return response()->json([
            'message' => 'Appointment cancelled',
            'appointment' => [
                'id' => $appt->id,
                'status' => $appt->status,
                'reference_code' => $appt->reference_code,
            ]
        ]);
    }

    /** Client reschedules their appointment (optionally picking staff) */
    public function reschedule(Request $request, int $id)
    {
        $user  = $request->user();
        $email = strtolower(trim($user->email));

        $data = $request->validate([
            'date'      => ['required','date_format:Y-m-d'],
            'starts_at' => ['required','date_format:H:i:s'],
            'staff_id'  => ['nullable','integer'],
            'notes'     => ['sometimes','nullable','string','max:10000'],
        ]);

        $appt = Appointment::with(['service','staff'])
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->findOrFail($id);

        if (!in_array($appt->status, ['pending','confirmed'])) {
            return response()->json(['message' => 'Only pending/confirmed appointments can be rescheduled.'], 422);
        }

        // Service snapshot
        $serviceId = $appt->service_id;
        $duration  = (int) ($appt->duration_minutes ?? $appt->service?->duration_minutes ?? 60);

        // Working hours & notice
        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));
        $workdayStartStr = (string) config('clinic.workday.start', '09:00:00');
        $workdayEndStr   = (string) config('clinic.workday.end',   '20:00:00');
        $minNotice       = (int) config('clinic.min_notice_minutes', 120);

        $date     = $data['date'];
        $startsAt = $data['starts_at'];
        $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startsAt);
        $slotEnd   = (clone $slotStart)->addMinutes($duration);
        $now       = Carbon::now();

        // Checks: not in past, meets min notice
        if ($slotEnd->lt($now)) {
            return response()->json(['message' => 'Cannot reschedule to a past time.'], 422);
        }
        if ($slotStart->lt((clone $now)->addMinutes($minNotice))) {
            return response()->json(['message' => "Please reschedule at least {$minNotice} minutes in advance."], 422);
        }

        // Inside working hours
        $workdayStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayStartStr);
        $workdayEnd   = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayEndStr);
        if ($slotStart->lt($workdayStart) || $slotEnd->gt($workdayEnd)) {
            return response()->json(['message' => 'Selected time is outside working hours.'], 422);
        }

        // Staff assignment (keep same staff by default)
        $assignedStaff = null;
        if (!empty($data['staff_id'])) {
            $st = Staff::where('is_active', true)->find($data['staff_id']);
            if (!$st || !$this->staffCoversService($st, $serviceId)) {
                return response()->json(['message' => 'Selected staff is not available for this service.'], 422);
            }
            $okWindow = $this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s'));
            $okFree   = $this->staffHasNoOverlap($st, $date, $startsAt, $duration, $ignoreId = $appt->id);
            if (!($okWindow && $okFree)) {
                return response()->json(['message' => 'Selected staff is not free at that time.'], 422);
            }
            if (!$this->serviceHasNoOverlap($serviceId, $date, $startsAt, $duration, $ignoreId = $appt->id)) {
                return response()->json(['message' => 'Service is occupied at that time.'], 422);
            }
            $assignedStaff = $st;
        } else {
            // keep existing staff if set, otherwise any eligible free one
            if ($appt->staff_id) {
                $st = Staff::where('is_active', true)->find($appt->staff_id);
                if ($st
                    && $this->staffCoversService($st, $serviceId)
                    && $this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s'))
                    && $this->staffHasNoOverlap($st, $date, $startsAt, $duration, $ignoreId = $appt->id)
                    && $this->serviceHasNoOverlap($serviceId, $date, $startsAt, $duration, $ignoreId = $appt->id)
                ) {
                    $assignedStaff = $st;
                }
            }
            if (!$assignedStaff) {
                // pick any eligible free staff
                $candidates = Staff::where('is_active', true)
                    ->whereHas('services', fn($q) => $q->where('services.id', $serviceId))
                    ->get();

                foreach ($candidates as $st) {
                    if (
                        $this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s')) &&
                        $this->staffHasNoOverlap($st, $date, $startsAt, $duration, $ignoreId = $appt->id) &&
                        $this->serviceHasNoOverlap($serviceId, $date, $startsAt, $duration, $ignoreId = $appt->id)
                    ) {
                        $assignedStaff = $st;
                        break;
                    }
                }
                if (!$assignedStaff) {
                    return response()->json(['message' => 'No staff available for that time.'], 422);
                }
            }
        }

        // Apply updates
        $appt->date             = $date;
        $appt->starts_at        = $startsAt;
        $appt->duration_minutes = $duration; // keep same duration
        $appt->staff_id         = $assignedStaff?->id;
        if (array_key_exists('notes', $data)) {
            $appt->notes = $data['notes'];
        }
        // Optionally reset to pending on reschedule
        if ($appt->status === 'confirmed') {
            $appt->status = 'pending';
        }
        $appt->save();

        return response()->json([
            'message' => 'Appointment rescheduled',
            'appointment' => [
                'id' => $appt->id,
                'date' => $appt->date,
                'starts_at' => $appt->starts_at,
                'duration_minutes' => (int)$appt->duration_minutes,
                'status' => $appt->status,
                'staff_id' => $appt->staff_id,
                'reference_code' => $appt->reference_code,
            ],
        ], 200);
    }

    // ====== Minimal helpers (same logic as in AppointmentPublicController) ======

    private function staffCoversService(Staff $staff, int $serviceId): bool
    {
        return $staff->services()->where('services.id', $serviceId)->exists();
    }

    private function staffWorksWindow(Staff $staff, string $date, string $startTime, string $endTime): bool
    {
        $weekday = (int) Carbon::createFromFormat('Y-m-d', $date)->dayOfWeek;

        $works = $staff->schedules()
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->where('start_time', '<=', $startTime)
            ->where('end_time', '>=', $endTime)
            ->exists();

        if (!$works) return false;

        $offs = $staff->timeOff()->where('date', $date)->get();
        foreach ($offs as $off) {
            if (is_null($off->start_time) && is_null($off->end_time)) return false;
            $oStart = $off->start_time ?? '00:00:00';
            $oEnd   = $off->end_time   ?? '23:59:59';
            if ($oStart < $endTime && $startTime < $oEnd) return false;
        }

        return true;
    }

    private function staffHasNoOverlap(Staff $staff, string $date, string $startTime, int $durationMinutes, ?int $ignoreId = null): bool
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "$date $startTime");
        $end   = (clone $start)->addMinutes($durationMinutes);

        $appts = $staff->appointments()
            ->whereDate('date', $date)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['id','date','starts_at','duration_minutes']);

        foreach ($appts as $a) {
            $aStart = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($a->date)->toDateString().' '.$a->starts_at);
            $aEnd   = (clone $aStart)->addMinutes((int) $a->duration_minutes);
            if ($aStart->lt($end) && $start->lt($aEnd)) return false;
        }
        return true;
    }

    private function serviceHasNoOverlap(int $serviceId, string $date, string $startTime, int $durationMinutes, ?int $ignoreId = null): bool
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "$date $startTime");
        $end   = (clone $start)->addMinutes($durationMinutes);

        $appts = Appointment::query()
            ->whereDate('date', $date)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->where('service_id', $serviceId)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['id','date','starts_at','duration_minutes']);

        foreach ($appts as $a) {
            $aStart = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($a->date)->toDateString().' '.$a->starts_at);
            $aEnd   = (clone $aStart)->addMinutes((int) $a->duration_minutes);
            if ($aStart->lt($end) && $start->lt($aEnd)) return false;
        }
        return true;
    }
}
