<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Http\Requests\Public\AvailabilityRequest;
use App\Http\Requests\Public\GuestBookingRequest;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServicePackage; // ✅ add import
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Events\AppointmentBookedEvent;

class AppointmentPublicController extends Controller
{
    // Fallbacks (config values take precedence) — keep H:i:s everywhere for consistency
    protected string $workdayStart = '09:30:00';
    protected string $workdayEnd   = '18:00:00';

    // 9C-1 — Availability (public)
    public function availability(AvailabilityRequest $request, Service $service)
    {
        // Guard: service must be publicly bookable
        if (!$service->is_active || !$service->is_bookable) {
            return response()->json(['message' => 'Service not available'], 404);
        }

        // Normalize timezone
        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));

        // 1) Normalized inputs
        $v    = $request->validated();
        $date = trim($v['date']); // YYYY-MM-DD

        // Optional staff filter
        $staffId = (int) ($v['staff_id'] ?? request()->input('staff_id', 0));

        // 2) Use service duration (fallback 60min)
        $duration = (int) ($service->duration_minutes ?? 60);

        // Working hours (config > fallback props)
        $workdayStartStr = (string) (config('clinic.workday.start', $this->workdayStart));
        $workdayEndStr   = (string) (config('clinic.workday.end',   $this->workdayEnd));

        // 3) Workday window
        $startOfDay = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayStartStr);
        $endOfDay   = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayEndStr);

        // 4) If date is today, skip past slots (round to next step)
        $stepMinutes = (int) config('clinic.slot_step', 15);
        $now = Carbon::now();
        if ($date === $now->toDateString()) {
            $earliest = (clone $now)->second(0);
            $remainder = $earliest->minute % $stepMinutes;
            if ($remainder !== 0) {
                $earliest->addMinutes($stepMinutes - $remainder);
            }
            if ($earliest->greaterThan($startOfDay)) {
                $startOfDay = $earliest;
            }
        }

        // 5) Eligible staff set
        if ($staffId) {
            $eligibleStaff = Staff::where('is_active', true)
                ->whereKey($staffId)
                ->get();

            if ($eligibleStaff->isEmpty() || !$this->staffCoversService($eligibleStaff->first(), $service->id)) {
                return response()->json([
                    'service_id'       => $service->id,
                    'date'             => $date,
                    'duration_minutes' => $duration,
                    'workday'          => ['start' => $workdayStartStr, 'end' => $workdayEndStr],
                    'available_slots'  => [],
                    'filters'          => ['staff_id' => $staffId],
                ]);
            }
        } else {
            $eligibleStaff = Staff::where('is_active', true)
                ->whereHas('services', fn($q) => $q->where('services.id', $service->id))
                ->get();

            if ($eligibleStaff->isEmpty()) {
                return response()->json([
                    'service_id'       => $service->id,
                    'date'             => $date,
                    'duration_minutes' => $duration,
                    'workday'          => ['start' => $workdayStartStr, 'end' => $workdayEndStr],
                    'available_slots'  => [],
                    'filters'          => ['staff_id' => null],
                ]);
            }
        }

        // === Prefetch same-day appointments to avoid N+1 (service + per-staff maps) ===
        $serviceIntervals = $this->fetchServiceIntervals($service->id, $date);
        $staffIntervals   = $this->fetchStaffIntervals($eligibleStaff->pluck('id')->all(), $date);

        // 6) Generate available slots (every step)
        $slots = [];
        for ($cursor = $startOfDay->copy(); $cursor->lessThan($endOfDay); $cursor->addMinutes($stepMinutes)) {
            $slotStart = $cursor->copy();
            $slotEnd   = $slotStart->copy()->addMinutes($duration);

            // Stop if slot ends after workday
            if ($slotEnd->greaterThan($endOfDay)) break;

            $slotOk = false;

            if ($staffId) {
                $st = $eligibleStaff->first();

                $slotOk =
                    $this->staffWorksWindow($st, $date, $slotStart->format('H:i:s'), $slotEnd->format('H:i:s')) &&
                    $this->noOverlapInIntervals($staffIntervals[$st->id] ?? [], $slotStart, $slotEnd) &&
                    $this->noOverlapInIntervals($serviceIntervals, $slotStart, $slotEnd);
            } else {
                foreach ($eligibleStaff as $st) {
                    if (
                        $this->staffWorksWindow($st, $date, $slotStart->format('H:i:s'), $slotEnd->format('H:i:s')) &&
                        $this->noOverlapInIntervals($staffIntervals[$st->id] ?? [], $slotStart, $slotEnd) &&
                        $this->noOverlapInIntervals($serviceIntervals, $slotStart, $slotEnd)
                    ) {
                        $slotOk = true;
                        break;
                    }
                }
            }

            if ($slotOk) {
                // return both HH:MM:SS and ISO range for nicer mobile UI
                $slots[] = [
                    'time' => $slotStart->format('H:i:s'),
                    'start_iso' => $slotStart->toIso8601String(),
                    'end_iso'   => $slotEnd->toIso8601String(),
                ];
            }
        }

        return response()->json([
            'service_id'       => $service->id,
            'date'             => $date,
            'duration_minutes' => $duration,
            'workday'          => ['start' => $workdayStartStr, 'end' => $workdayEndStr],
            'available_slots'  => $slots,
            'filters'          => ['staff_id' => $staffId ?: null],
        ]);
    }

    // 9C-2 — Guest booking (public)
    public function guestBook(GuestBookingRequest $request, Service $service)
    {
        // ✅ Require authenticated user
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        // Guard: service must be publicly bookable
        if (!$service->is_active || !$service->is_bookable) {
            return response()->json(['message' => 'Service not available'], 404);
        }

        // === Business rules & config ===
        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));
        $workdayStartStr = (string) config('clinic.workday.start', $this->workdayStart);
        $workdayEndStr   = (string) config('clinic.workday.end',   $this->workdayEnd);
        $minNotice       = (int) config('clinic.min_notice_minutes', 10);

        // === Inputs ===
        $v        = $request->validated();
        $date     = trim($v['date']);        // 'Y-m-d'
        $startsAt = trim($v['starts_at']);   // 'H:i:s'
        $staffId  = (int) ($v['staff_id'] ?? 0);

        // === Service snapshot at booking time ===
        $duration = (int) ($service->duration_minutes ?? 60);
        $price    = (float) ($service->price ?? 0);

        // === Time window checks ===
        $workdayStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayStartStr);
        $workdayEnd   = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayEndStr);

        $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startsAt);
        $slotEnd   = (clone $slotStart)->addMinutes($duration);

        if ($slotStart->lt($workdayStart) || $slotEnd->gt($workdayEnd)) {
            return response()->json(['message' => 'Selected time is outside working hours.'], 422);
        }

        $now = Carbon::now();
        if ($slotEnd->lt($now)) {
            return response()->json(['message' => 'Cannot book a time in the past.'], 422);
        }

        $earliestAllowed = (clone $now)->addMinutes($minNotice);
        if ($slotStart->lt($earliestAllowed)) {
            return response()->json(['message' => "Please book at least {$minNotice} minutes in advance."], 422);
        }

        // === Staff selection ===
        $assignedStaff = null;

        if ($staffId) {
            $st = Staff::where('is_active', true)->find($staffId);
            if (!$st || !$this->staffCoversService($st, $service->id)) {
                return response()->json(['message' => 'Selected staff is not available for this service.'], 422);
            }

            if (
                !$this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s')) ||
                !$this->staffHasNoOverlap($st, $date, $startsAt, $duration) ||
                !$this->serviceHasNoOverlap($service->id, $date, $startsAt, $duration)
            ) {
                return response()->json(['message' => 'Selected staff is not free at that time.'], 422);
            }

            $assignedStaff = $st;
        } else {
            $candidates = Staff::where('is_active', true)
                ->whereHas('services', fn($q) => $q->where('services.id', $service->id))
                ->get();

            foreach ($candidates as $st) {
                if (
                    $this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s')) &&
                    $this->staffHasNoOverlap($st, $date, $startsAt, $duration) &&
                    $this->serviceHasNoOverlap($service->id, $date, $startsAt, $duration)
                ) {
                    $assignedStaff = $st;
                    break;
                }
            }

            if (!$assignedStaff) {
                return response()->json(['message' => 'No staff available for that time.'], 422);
            }
        }

        // === Idempotency (best-effort) ===
        $idem = $request->header('Idempotency-Key');
        if ($idem) {
            $existing = Appointment::query()
                ->where('service_id', $service->id)
                ->where('staff_id', $assignedStaff->id)
                ->whereDate('date', $date)
                ->where('starts_at', $startsAt)
                ->whereIn('status', ['pending','confirmed'])
                ->first();

            if ($existing) {
                $existing->loadMissing(['service','client','staff.user']);

                return response()->json([
                    'message'     => 'Appointment booked',
                    'appointment' => $this->presentAppointment($existing),
                    'idempotent'  => true,
                ], 201);
            }
        }

        // === Create appointment ===
        $code = $this->newReferenceCode();

        $appt = Appointment::create([
            'service_id'       => $service->id,
            'staff_id'         => $assignedStaff->id,
            'user_id'          => $user->id,
            'date'             => $date,
            'starts_at'        => $startsAt,
            'duration_minutes' => $duration,
            'price'            => $price, // For display; package billing lives on ServicePackage for bundles
            'customer_name'    => $user->name  ?? ($v['customer_name']  ?? null),
            'customer_email'   => $user->email ?? ($v['customer_email'] ?? null),
            'customer_phone'   => $user->phone ?? ($v['customer_phone'] ?? null),
            'status'           => Appointment::STATUS_PENDING,
            'notes'            => $v['notes'] ?? null,
            'reference_code'   => $code,
        ]);

        // === PACKAGE LOGIC: create or attach (sessions packages only) ===

        // We only apply package logic when:
        // - service is marked as package
        // - it has total_sessions > 0 (Laser, 6 sessions, etc.)
        // - it is bookable (normal calendar appointment)
        $isSessionPackage = ($service->is_package && $service->is_bookable && ($service->total_sessions ?? 0) > 0);

        if ($isSessionPackage) {
            // 1) Find oldest active package with remaining sessions for this user + service
            $pkg = ServicePackage::query()
                ->where('user_id', $user->id)
                ->where('service_id', $service->id)
                ->where('status', ServicePackage::STATUS_ACTIVE)
                ->whereNotNull('remaining_sessions')
                ->where('remaining_sessions', '>', 0)
                ->orderBy('starts_on')       // oldest by start date
                ->orderBy('created_at')      // tie-breaker
                ->first();

            // 2) If no active package, create a new one for this booking
            if (!$pkg) {
                $totalSessions = (int) ($service->total_sessions ?? 0);

                $pkg = ServicePackage::create([
                    'user_id'                 => $user->id,
                    'service_id'              => $service->id,
                    'service_name'            => $service->name,

                    'snapshot_total_sessions' => $totalSessions,
                    'snapshot_total_minutes'  => null,
                    'remaining_sessions'      => $totalSessions,
                    'remaining_minutes'       => null,

                    'price_total'             => $service->price, // full package value, e.g. 450€
                    'price_paid'              => $service->price, // legacy mirror if you still use it
                    'currency'                => 'EUR',           // or pull from config

                    'status'                  => ServicePackage::STATUS_ACTIVE,
                    'starts_on'               => now()->toDateString(),
                    'expires_on'              => null,
                    'notes'                   => 'Auto-created from online booking',
                ]);
            }

            // 3) Attach appointment to the chosen package
            $appt->service_package_id = $pkg->id;
            $appt->save();
        }

        // === Events & response ===
        $appt->loadMissing(['service','client','staff.user']);
        event(new AppointmentBookedEvent($appt));
        if (class_exists(\App\Events\AppointmentBooked::class)) {
            event(new \App\Events\AppointmentBooked($appt));
        }

        return response()->json([
            'message'     => 'Appointment booked',
            'appointment' => $this->presentAppointment($appt),
        ], 201);
    }

    /**
     * Uniform appointment payload for mobile/clients.
     */
    private function presentAppointment(Appointment $a): array
    {
        $date = $a->date instanceof Carbon
            ? $a->date->toDateString()
            : Carbon::parse($a->date)->toDateString();

        $start = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$a->starts_at);
        $end   = (clone $start)->addMinutes((int) $a->duration_minutes);

        return [
            'id'               => $a->id,
            'reference_code'   => $a->reference_code,
            'service'          => [
                'id'   => $a->service?->id,
                'name' => $a->service?->name,
                'slug' => $a->service?->slug,
            ],
            'staff'            => $a->staff ? [
                'id'   => $a->staff->id,
                'name' => $a->staff->name,
            ] : null,
            'date'             => $date,                         // YYYY-MM-DD
            'time'             => $a->starts_at,                 // HH:MM:SS
            'end_time'         => $end->format('H:i:s'),         // HH:MM:SS
            'duration_minutes' => (int) $a->duration_minutes,
            'price'            => (string) $a->price,
            'status'           => $a->status,
            'customer'         => [
                'name'  => $a->customer_name,
                'email' => $a->customer_email,
                'phone' => $a->customer_phone,
            ],
            'display' => [
                'date_time' => $start->format('Y-m-d H:i'),
                'range'     => $start->format('H:i').'–'.$end->format('H:i'),
            ],
        ];
    }

    // 9C-3 — Public lookup by reference code
    public function showByCode(string $code)
    {
        $appt = Appointment::with(['service','staff'])
            ->where('reference_code', strtoupper($code))
            ->first();

        if (!$appt) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['appointment' => $this->presentAppointment($appt)]);
    }

    /**
     * Generate an 8–10 char uppercase reference code (unique).
     */
    protected function newReferenceCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (Appointment::where('reference_code', $code)->exists());

        return $code;
    }

    // =================== STAFF/RESOURCE HELPERS =================== //

    private function staffCoversService(Staff $staff, int $serviceId): bool
    {
        return $staff->services()->where('services.id', $serviceId)->exists();
    }

    private function staffWorksWindow(Staff $staff, string $date, string $startTime, string $endTime): bool
    {
        $weekday = (int) Carbon::createFromFormat('Y-m-d', $date)->dayOfWeek; // 0..6

        // Check recurring weekly schedule
        $works = $staff->schedules()
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->where('start_time', '<=', $startTime)
            ->where('end_time', '>=', $endTime)
            ->exists();

        if (!$works) return false;

        // Check time off
        $offs = $staff->timeOff()->where('date', $date)->get();
        foreach ($offs as $off) {
            if (is_null($off->start_time) && is_null($off->end_time)) {
                return false; // whole day off
            }
            $oStart = $off->start_time ?? '00:00:00';
            $oEnd   = $off->end_time ?? '23:59:59';
            if ($oStart < $endTime && $startTime < $oEnd) {
                return false; // partial overlap
            }
        }

        return true;
    }

    private function staffHasNoOverlap(Staff $staff, string $date, string $startTime, int $durationMinutes): bool
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "$date $startTime");
        $end   = (clone $start)->addMinutes($durationMinutes);

        $appts = $staff->appointments()
            ->whereDate('date', $date)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['date','starts_at','duration_minutes']);

        foreach ($appts as $a) {
            $aStart = ($a->date instanceof Carbon ? $a->date : Carbon::parse($a->date))
                ->copy()->setTimeFromTimeString($a->starts_at);
            $aEnd = (clone $aStart)->addMinutes((int) $a->duration_minutes);

            if ($aStart->lt($end) && $start->lt($aEnd)) {
                return false; // overlapping appointment
            }
        }

        return true;
    }

    /**
     * Service-level overlap prevention (global resource guard).
     */
    private function serviceHasNoOverlap(int $serviceId, string $date, string $startTime, int $durationMinutes): bool
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "$date $startTime");
        $end   = (clone $start)->addMinutes($durationMinutes);

        $appts = Appointment::query()
            ->whereDate('date', $date)
            ->where('service_id', $serviceId)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['date','starts_at','duration_minutes']);

        foreach ($appts as $a) {
            $aDate  = $a->date instanceof Carbon ? $a->date : Carbon::parse($a->date);
            $aStart = (clone $aDate)->setTimeFromTimeString($a->starts_at);
            $aEnd   = (clone $aStart)->addMinutes((int) $a->duration_minutes);

            if ($aStart->lt($end) && $start->lt($aEnd)) {
                return false; // overlapping service usage
            }
        }

        return true;
    }

    // =================== Prefetch helpers (performance) =================== //

    /**
     * Fetch all intervals for a service on a date (pending|confirmed|completed).
     * Returns array of [Carbon start, Carbon end].
     */
    private function fetchServiceIntervals(int $serviceId, string $date): array
    {
        $rows = Appointment::query()
            ->where('service_id', $serviceId)
            ->whereDate('date', $date)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['date','starts_at','duration_minutes']);

        $out = [];
        foreach ($rows as $a) {
            $start = ($a->date instanceof Carbon ? $a->date : Carbon::parse($a->date))
                ->copy()->setTimeFromTimeString($a->starts_at);
            $end = (clone $start)->addMinutes((int) $a->duration_minutes);
            $out[] = [$start, $end];
        }
        return $out;
    }

    /**
     * Fetch all intervals for given staff IDs on a date.
     * Returns map: staff_id => array of [Carbon start, Carbon end]
     */
    private function fetchStaffIntervals(array $staffIds, string $date): array
    {
        if (empty($staffIds)) return [];
        $rows = Appointment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereDate('date', $date)
            ->whereIn('status', ['pending','confirmed','completed'])
            ->get(['staff_id','date','starts_at','duration_minutes']);

        $map = [];
        foreach ($rows as $a) {
            $start = ($a->date instanceof Carbon ? $a->date : Carbon::parse($a->date))
                ->copy()->setTimeFromTimeString($a->starts_at);
            $end = (clone $start)->addMinutes((int) $a->duration_minutes);
            $map[$a->staff_id][] = [$start, $end];
        }
        return $map;
    }

    /**
     * Pure in-memory overlap check against an intervals array [[start,end],...]
     */
    private function noOverlapInIntervals(array $intervals, Carbon $start, Carbon $end): bool
    {
        foreach ($intervals as [$aStart, $aEnd]) {
            if ($aStart->lt($end) && $start->lt($aEnd)) {
                return false;
            }
        }
        return true;
    }

    // =================== Staff list for service (with optional availability) =================== //

    /**
     * List active staff who can perform a service.
     * Optional query: date=YYYY-MM-DD & starts_at=HH:MM:SS (adds "available" boolean)
     * Optional: duration_minutes (defaults to service.duration_minutes or 60)
     */
    public function staffForService(Request $request, Service $service)
    {
        if (!$service->is_active) {
            return response()->json(['message' => 'Service not available'], 404);
        }

        $date      = $request->query('date');        // YYYY-MM-DD
        $startsAt  = $request->query('starts_at');   // HH:MM:SS
        $duration  = (int) ($request->integer('duration_minutes') ?? ($service->duration_minutes ?? 60));

        $staff = Staff::query()
            ->where('is_active', true)
            ->whereHas('services', fn($q) => $q->where('services.id', $service->id))
            ->select('id','name','email','phone','is_active')
            ->orderBy('name')
            ->get();

        if (!$date || !$startsAt) {
            return response()->json([
                'service' => ['id' => $service->id, 'name' => $service->name],
                'filters' => ['date' => null, 'starts_at' => null, 'duration_minutes' => $duration],
                'staff'   => $staff->map(fn($s) => [
                    'id'         => $s->id,
                    'name'       => $s->name,
                    'email'      => $s->email,
                    'phone'      => $s->phone,
                    'is_active'  => (bool) $s->is_active,
                    'available'  => null,
                ])->values(),
            ]);
        }

        $workdayStartStr = (string) config('clinic.workday.start', $this->workdayStart);
        $workdayEndStr   = (string) config('clinic.workday.end',   $this->workdayEnd);

        $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startsAt);
        $slotEnd   = (clone $slotStart)->addMinutes($duration);

        $workdayStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayStartStr);
        $workdayEnd   = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayEndStr);
        $insideHours  = !($slotStart->lt($workdayStart) || $slotEnd->gt($workdayEnd));

        // Prefetch intervals for all staff once
        $staffIntervals   = $this->fetchStaffIntervals($staff->pluck('id')->all(), $date);
        $serviceIntervals = $this->fetchServiceIntervals($service->id, $date);

        $result = $staff->map(function ($s) use ($service, $date, $startsAt, $duration, $slotStart, $slotEnd, $insideHours, $staffIntervals, $serviceIntervals) {
            $okWindow = $insideHours
                ? $this->staffWorksWindow($s, $date, $startsAt, $slotEnd->format('H:i:s'))
                : false;

            $okFree = $okWindow
                ? $this->noOverlapInIntervals($staffIntervals[$s->id] ?? [], $slotStart, $slotEnd)
                : false;

            $okService = $okFree
                ? $this->noOverlapInIntervals($serviceIntervals, $slotStart, $slotEnd)
                : false;

            return [
                'id'         => $s->id,
                'name'       => $s->name,
                'email'      => $s->email,
                'phone'      => $s->phone,
                'is_active'  => (bool) $s->is_active,
                'available'  => $insideHours && $okWindow && $okFree && $okService,
            ];
        })->values();

        return response()->json([
            'service' => ['id' => $service->id, 'name' => $service->name],
            'filters' => ['date' => $date, 'starts_at' => $startsAt, 'duration_minutes' => $duration],
            'staff'   => $result,
        ]);
    }
}
