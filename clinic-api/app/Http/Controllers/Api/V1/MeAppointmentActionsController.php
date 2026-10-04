<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Staff;
use App\Services\BookingGroupAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeAppointmentActionsController extends Controller
{
    public function cancel(Request $request, int $id)
    {
        $email = strtolower(trim((string) $request->user()->email));
        $appt = Appointment::with(['service','staff'])->whereRaw('LOWER(customer_email) = ?', [$email])->findOrFail($id);
        $targets = $appt->booking_group_id
            ? Appointment::where('booking_group_id', $appt->booking_group_id)->whereRaw('LOWER(customer_email) = ?', [$email])->orderBy('starts_at')->get()
            : collect([$appt]);

        foreach ($targets as $item) {
            if (!in_array($item->status, ['pending','confirmed'], true)) {
                return response()->json(['message' => 'Every treatment in this visit must still be pending or confirmed before it can be cancelled.'], 422);
            }
        }

        $timezone = config('clinic.timezone', config('app.timezone'));
        $start = Carbon::parse(Carbon::parse($targets->first()->date)->toDateString().' '.$targets->first()->starts_at, $timezone);
        $now = Carbon::now($timezone);
        $minNotice = (int) config('clinic.min_notice_minutes', 120);
        if ($start->lt($now)) return response()->json(['message' => 'Cannot cancel past appointments.'], 422);
        if ($start->lt($now->copy()->addMinutes($minNotice))) return response()->json(['message' => "Please cancel at least {$minNotice} minutes in advance."], 422);

        DB::transaction(function () use ($targets) {
            foreach ($targets as $item) { $item->status = 'cancelled'; $item->save(); }
        });

        return response()->json([
            'message' => $appt->booking_group_id ? 'Joined visit cancelled' : 'Appointment cancelled',
            'booking_group_id' => $appt->booking_group_id ? (int) $appt->booking_group_id : null,
            'appointment_ids' => $targets->pluck('id')->map(fn($v)=>(int)$v)->values(),
        ]);
    }

    public function reschedule(Request $request, int $id, BookingGroupAvailabilityService $groupAvailability)
    {
        $email = strtolower(trim((string) $request->user()->email));
        $data = $request->validate([
            'date' => ['required','date_format:Y-m-d'],
            'starts_at' => ['required','date_format:H:i:s'],
            'staff_id' => ['nullable','integer'],
            'notes' => ['sometimes','nullable','string','max:10000'],
        ]);
        $appt = Appointment::with(['service','staff'])->whereRaw('LOWER(customer_email) = ?', [$email])->findOrFail($id);
        if ($appt->booking_group_id) return $this->rescheduleGroup($appt, $data, $email, $groupAvailability);

        if (!in_array($appt->status, ['pending','confirmed'], true)) return response()->json(['message' => 'Only pending/confirmed appointments can be rescheduled.'], 422);
        $serviceId=$appt->service_id; $duration=(int)($appt->duration_minutes ?? $appt->service?->duration_minutes ?? 60);
        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));
        $date=$data['date']; $startsAt=$data['starts_at']; $minNotice=(int)config('clinic.min_notice_minutes',120);
        $slotStart=Carbon::createFromFormat('Y-m-d H:i:s',$date.' '.$startsAt); $slotEnd=(clone $slotStart)->addMinutes($duration); $now=Carbon::now();
        if($slotEnd->lt($now)) return response()->json(['message'=>'Cannot reschedule to a past time.'],422);
        if($slotStart->lt((clone $now)->addMinutes($minNotice))) return response()->json(['message'=>"Please reschedule at least {$minNotice} minutes in advance."],422);
        $workdayStart=Carbon::createFromFormat('Y-m-d H:i:s',$date.' '.(string)config('clinic.workday.start','09:00:00'));
        $workdayEnd=Carbon::createFromFormat('Y-m-d H:i:s',$date.' '.(string)config('clinic.workday.end','20:00:00'));
        if($slotStart->lt($workdayStart)||$slotEnd->gt($workdayEnd)) return response()->json(['message'=>'Selected time is outside working hours.'],422);

        $candidateIds=!empty($data['staff_id'])?[(int)$data['staff_id']]:array_values(array_unique(array_filter([(int)$appt->staff_id])));
        if(empty($candidateIds)) $candidateIds=Staff::where('is_active',true)->whereHas('services',fn($q)=>$q->where('services.id',$serviceId))->pluck('id')->map(fn($v)=>(int)$v)->all();
        $assignedStaff=null;
        foreach($candidateIds as $candidateId){
            $st=Staff::where('is_active',true)->find($candidateId);
            if($st && $this->staffCoversService($st,$serviceId) && $this->staffWorksWindow($st,$date,$startsAt,$slotEnd->format('H:i:s')) && $this->staffHasNoOverlap($st,$date,$startsAt,$duration,$appt->id) && $this->serviceHasNoOverlap($serviceId,$date,$startsAt,$duration,$appt->id)){ $assignedStaff=$st; break; }
        }
        if(!$assignedStaff) return response()->json(['message'=>'Selected staff is not free at that time.'],422);
        $appt->date=$date; $appt->starts_at=$startsAt; $appt->duration_minutes=$duration; $appt->staff_id=$assignedStaff->id;
        if(array_key_exists('notes',$data)) $appt->notes=$data['notes'];
        if($appt->status==='confirmed') $appt->status='pending';
        $appt->save();
        return response()->json(['message'=>'Appointment rescheduled','appointment'=>['id'=>$appt->id,'date'=>$appt->date,'starts_at'=>$appt->starts_at,'duration_minutes'=>(int)$appt->duration_minutes,'status'=>$appt->status,'staff_id'=>$appt->staff_id,'reference_code'=>$appt->reference_code]],200);
    }

    private function rescheduleGroup(Appointment $anchor, array $data, string $email, BookingGroupAvailabilityService $availability)
    {
        $items=Appointment::with('service')->where('booking_group_id',$anchor->booking_group_id)->whereRaw('LOWER(customer_email) = ?',[$email])->orderBy('starts_at')->get();
        if($items->count()<2) throw ValidationException::withMessages(['booking_group'=>'This joined visit is incomplete.']);
        foreach($items as $item) if(!in_array($item->status,['pending','confirmed'],true)) throw ValidationException::withMessages(['booking_group'=>'Every treatment must still be pending or confirmed to reschedule this visit.']);
        $timezone=config('clinic.timezone',config('app.timezone')); $start=Carbon::createFromFormat('Y-m-d H:i:s',$data['date'].' '.$data['starts_at'],$timezone);
        $minNotice=(int)config('clinic.min_notice_minutes',120);
        if($start->lt(Carbon::now($timezone)->addMinutes($minNotice))) throw ValidationException::withMessages(['starts_at'=>"Please reschedule at least {$minNotice} minutes in advance."]);
        $staffId=(int)($data['staff_id'] ?: $items->first()->staff_id);
        $serviceIds=$items->pluck('service_id')->map(fn($v)=>(int)$v)->all(); $ignoreIds=$items->pluck('id')->map(fn($v)=>(int)$v)->all();
        $availability->validateBlock($serviceIds,$staffId,$data['date'],substr($data['starts_at'],0,5),$ignoreIds);

        DB::transaction(function() use($items,$data,$staffId,$start){
            $offset=0;
            foreach($items as $item){
                $item->date=$data['date']; $item->starts_at=$start->copy()->addMinutes($offset)->format('H:i:s'); $item->staff_id=$staffId;
                if($item->status==='confirmed') $item->status='pending'; $item->save();
                $offset+=max(1,(int)($item->duration_minutes ?? $item->service?->duration_minutes ?? 60));
            }
        });
        return response()->json(['message'=>'Joined visit rescheduled','booking_group_id'=>(int)$anchor->booking_group_id,'appointment_ids'=>$items->pluck('id')->map(fn($v)=>(int)$v)->values(),'date'=>$data['date'],'starts_at'=>$data['starts_at'],'staff_id'=>$staffId]);
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
