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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    // 9C-2 — Authenticated client booking
    public function guestBook(GuestBookingRequest $request, Service $service)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Authentication required'], 401);
        }

        if (! $service->is_active || ! $service->is_bookable || $service->isQuantityPackage()) {
            return response()->json(['message' => 'Service not available for appointments'], 404);
        }

        date_default_timezone_set(config('clinic.timezone', config('app.timezone')));
        $workdayStartStr = (string) config('clinic.workday.start', $this->workdayStart);
        $workdayEndStr = (string) config('clinic.workday.end', $this->workdayEnd);
        $minNotice = (int) config('clinic.min_notice_minutes', 10);

        $v = $request->validated();
        $date = trim($v['date']);
        $startsAt = trim($v['starts_at']);
        $staffId = (int) ($v['staff_id'] ?? 0);
        $requestedPackageId = isset($v['service_package_id']) ? (int) $v['service_package_id'] : null;

        $duration = (int) ($service->duration_minutes ?? 60);
        $price = (float) ($service->price ?? 0);

        $workdayStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayStartStr);
        $workdayEnd = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$workdayEndStr);
        $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startsAt);
        $slotEnd = (clone $slotStart)->addMinutes($duration);

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

        $selectedPackage = null;
        $createPackage = false;

        if ($service->isSessionPackage()) {
            if ($requestedPackageId) {
                $selectedPackage = ServicePackage::query()
                    ->whereKey($requestedPackageId)
                    ->where('user_id', $user->id)
                    ->where('service_id', $service->id)
                    ->first();

                if (! $selectedPackage) {
                    throw ValidationException::withMessages([
                        'service_package_id' => 'The selected package does not belong to you or is for another service.',
                    ]);
                }
            } else {
                $availablePackages = ServicePackage::query()
                    ->where('user_id', $user->id)
                    ->where('service_id', $service->id)
                    ->where('status', ServicePackage::STATUS_ACTIVE)
                    ->where('remaining_sessions', '>', 0)
                    ->orderBy('starts_on')
                    ->orderBy('id')
                    ->get();

                if ($availablePackages->count() > 1) {
                    throw ValidationException::withMessages([
                        'service_package_id' => 'You have multiple active packages for this service. Select the exact package to use.',
                    ]);
                }

                $selectedPackage = $availablePackages->first();
                $createPackage = ! $selectedPackage;
            }

            if ($selectedPackage) {
                $this->assertPackageCanBeBooked($selectedPackage, $service, $user->id, $date);

                if ($selectedPackage->staffPolicy() === Service::STAFF_SAME && $selectedPackage->assigned_staff_id) {
                    if ($staffId && $staffId !== (int) $selectedPackage->assigned_staff_id) {
                        throw ValidationException::withMessages([
                            'staff_id' => 'This package is assigned to a different staff member.',
                        ]);
                    }
                    $staffId = (int) $selectedPackage->assigned_staff_id;
                }
            }
        } elseif ($requestedPackageId) {
            throw ValidationException::withMessages([
                'service_package_id' => 'Single appointments cannot be linked to a package.',
            ]);
        }

        $assignedStaff = null;
        if ($staffId) {
            $st = Staff::where('is_active', true)->find($staffId);
            if (! $st || ! $this->staffCoversService($st, $service->id)) {
                return response()->json(['message' => 'Selected staff is not available for this service.'], 422);
            }

            if (
                ! $this->staffWorksWindow($st, $date, $startsAt, $slotEnd->format('H:i:s')) ||
                ! $this->staffHasNoOverlap($st, $date, $startsAt, $duration) ||
                ! $this->serviceHasNoOverlap($service->id, $date, $startsAt, $duration)
            ) {
                return response()->json(['message' => 'Selected staff is not free at that time.'], 422);
            }

            $assignedStaff = $st;
        } else {
            $candidates = Staff::where('is_active', true)
                ->whereHas('services', fn ($q) => $q->where('services.id', $service->id))
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

            if (! $assignedStaff) {
                return response()->json(['message' => 'No staff available for that time.'], 422);
            }
        }

        $idem = $request->header('Idempotency-Key');
        if ($idem) {
            $existing = Appointment::query()
                ->where('user_id', $user->id)
                ->where('service_id', $service->id)
                ->where('staff_id', $assignedStaff->id)
                ->whereDate('date', $date)
                ->where('starts_at', $startsAt)
                ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                ->first();

            if ($existing) {
                $existing->loadMissing(['service', 'client', 'staff.user', 'package']);

                return response()->json([
                    'message' => 'Appointment booked',
                    'appointment' => $this->presentAppointment($existing),
                    'idempotent' => true,
                ], 201);
            }
        }

        $appt = DB::transaction(function () use (
            $service,
            $user,
            $selectedPackage,
            $createPackage,
            $assignedStaff,
            $date,
            $startsAt,
            $duration,
            $price,
            $v
        ) {
            $package = null;

            if ($service->isSessionPackage()) {
                if ($createPackage) {
                    $totalSessions = (int) $service->total_sessions;
                    if ($totalSessions <= 0) {
                        throw ValidationException::withMessages([
                            'service' => 'This package does not have included sessions configured.',
                        ]);
                    }

                    $package = ServicePackage::create([
                        'user_id' => $user->id,
                        'service_id' => $service->id,
                        'assigned_staff_id' => $service->staff_policy === Service::STAFF_SAME
                            ? $assignedStaff->id
                            : null,
                        'service_name' => $service->name,
                        'snapshot_total_sessions' => $totalSessions,
                        'snapshot_total_minutes' => null,
                        'snapshot_usage_type' => Service::USAGE_SESSION,
                        'snapshot_minimum_interval_days' => (int) ($service->minimum_interval_days ?? 0),
                        'snapshot_deduction_method' => $service->deduction_method ?: Service::DEDUCTION_AUTOMATIC,
                        'snapshot_staff_policy' => $service->staff_policy ?: Service::STAFF_ANY_QUALIFIED,
                        'snapshot_duration_minutes' => $service->duration_minutes,
                        'remaining_sessions' => $totalSessions,
                        'remaining_minutes' => null,
                        'price_total' => $service->price,
                        'price_paid' => 0,
                        'currency' => 'EUR',
                        'status' => ServicePackage::STATUS_ACTIVE,
                        'starts_on' => now()->toDateString(),
                        'expires_on' => null,
                        'notes' => 'Created from client booking',
                    ]);
                } else {
                    $package = ServicePackage::query()
                        ->lockForUpdate()
                        ->findOrFail($selectedPackage->id);

                    $this->assertPackageCanBeBooked($package, $service, $user->id, $date);

                    $reservedSessions = Appointment::query()
                        ->where('service_package_id', $package->id)
                        ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                        ->count();

                    if ($reservedSessions >= (int) $package->remaining_sessions) {
                        throw ValidationException::withMessages([
                            'service_package_id' => 'All remaining sessions in this package are already booked.',
                        ]);
                    }

                    if ($package->staffPolicy() === Service::STAFF_SAME) {
                        if ($package->assigned_staff_id && (int) $package->assigned_staff_id !== (int) $assignedStaff->id) {
                            throw ValidationException::withMessages([
                                'staff_id' => 'This package is assigned to a different staff member.',
                            ]);
                        }

                        if (! $package->assigned_staff_id) {
                            $package->assigned_staff_id = $assignedStaff->id;
                            $package->save();
                        }
                    }
                }
            }

            return Appointment::create([
                'service_id' => $service->id,
                'service_package_id' => $package?->id,
                'staff_id' => $assignedStaff->id,
                'user_id' => $user->id,
                'date' => $date,
                'starts_at' => $startsAt,
                'duration_minutes' => $duration,
                'price' => $price,
                'customer_name' => $user->name ?? ($v['customer_name'] ?? null),
                'customer_email' => $user->email ?? ($v['customer_email'] ?? null),
                'customer_phone' => $user->phone ?? ($v['customer_phone'] ?? null),
                'status' => Appointment::STATUS_PENDING,
                'source' => Appointment::SOURCE_CLIENT_BOOKING,
                'notes' => $v['notes'] ?? null,
                'reference_code' => $this->newReferenceCode(),
            ]);
        });

        $appt->loadMissing(['service', 'client', 'staff.user', 'package']);
        event(new AppointmentBookedEvent($appt));
        if (class_exists(\App\Events\AppointmentBooked::class)) {
            event(new \App\Events\AppointmentBooked($appt));
        }

        return response()->json([
            'message' => 'Appointment booked',
            'appointment' => $this->presentAppointment($appt),
        ], 201);
    }

    private function assertPackageCanBeBooked(
        ServicePackage $package,
        Service $service,
        int $userId,
        string $date,
    ): void {
        if ((int) $package->user_id !== $userId || (int) $package->service_id !== (int) $service->id) {
            throw ValidationException::withMessages([
                'service_package_id' => 'The selected package does not match this client and service.',
            ]);
        }

        try {
            $package->assertUsableOn($date);
        } catch (\LogicException $exception) {
            throw ValidationException::withMessages([
                'service_package_id' => $exception->getMessage(),
            ]);
        }

        if (! $package->isSessionsType() || $package->deductionMethod() !== Service::DEDUCTION_AUTOMATIC) {
            throw ValidationException::withMessages([
                'service_package_id' => 'Only automatic session packages can be used for appointments.',
            ]);
        }

        if ((int) $package->remaining_sessions <= 0) {
            throw ValidationException::withMessages([
                'service_package_id' => 'This package has no sessions remaining.',
            ]);
        }

        if ($package->next_allowed_date && Carbon::parse($date)->lt(Carbon::parse($package->next_allowed_date))) {
            throw ValidationException::withMessages([
                'date' => "The next package session is allowed from {$package->next_allowed_date}.",
            ]);
        }
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
            'service_package_id' => $a->service_package_id,
            'source'             => $a->source,
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
