<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentStoreRequest;
use App\Http\Requests\Admin\AppointmentUpdateRequest;
use App\Http\Requests\Admin\AdminUpdateAppointmentStatusRequest;
use App\Http\Requests\Admin\AssignStaffRequest;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\PackageLog;
use App\Models\PackagePayment;
use App\Services\AppointmentCompletionService;
use App\Services\AdminAppointmentUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\User;

class AppointmentAdminController extends Controller
{
    /**
     * Clinic working hours are hardcoded because you said they are not stored in DB.
     * Carbon dayOfWeek: 0 = Sunday, 1 = Monday, ... 6 = Saturday.
     * Change these times here if the clinic schedule changes.
     */
    private const CLINIC_WORKING_HOURS = [
        0 => null, // Sunday closed
        1 => ['09:00', '18:00'], // Monday
        2 => ['09:00', '18:00'], // Tuesday
        3 => ['09:00', '18:00'], // Wednesday
        4 => ['09:00', '18:00'], // Thursday
        5 => ['09:00', '18:00'], // Friday
        6 => ['09:00', '16:00'], // Saturday
    ];

    // 4) Admin list (filters: date, status, service_id, staff_id). Basic sorts.
    public function index(Request $request)
    {
        $q = Appointment::query()->with(['service','staff']);

        if ($request->filled('date')) {
            $q->whereDate('date', Carbon::parse($request->query('date'))->toDateString());
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('service_id')) {
            $q->where('service_id', (int) $request->input('service_id'));
        }
        if ($request->filled('staff_id')) {
            $q->where('staff_id', (int) $request->input('staff_id'));
        }

        // Sorting (date_time default)
        $sort = $request->input('sort', 'date_time'); // date_time|created_at|status|price
        $dir  = $request->input('dir') === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'created_at':
                $q->orderBy('created_at', $dir)->orderBy('id', $dir);
                break;
            case 'status':
                $q->orderBy('status', $dir)->orderBy('date', 'asc')->orderBy('starts_at', 'asc');
                break;
            case 'price':
                $q->orderBy('price', $dir)->orderBy('date', 'asc')->orderBy('starts_at', 'asc');
                break;
            case 'date_time':
            default:
                $q->orderBy('date', $dir)->orderBy('starts_at', $dir)->orderBy('id', 'asc');
                break;
        }

        $perPage = min(100, (int) $request->input('per_page', 20));
        $items = $q->paginate($perPage);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }

   
    public function calendar(Request $request)
    {
        $data = $request->validate([
            'from'       => ['required', 'date_format:Y-m-d'],
            'to'         => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status'     => ['nullable'], // string "pending,confirmed" OR array
            'staff_id'   => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay();
        $to   = Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay();

        $q = Appointment::query()
            ->with([
                'service.category',
                'staff',
                'user',
                'servicePackage',
                // If you have a payments relation on Appointment, uncomment:
                // 'payments',
            ])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        // status filter: supports CSV or array
        if (!empty($data['status'])) {
            $statuses = is_array($data['status'])
                ? $data['status']
                : array_values(array_filter(array_map('trim', explode(',', (string) $data['status']))));

            if (!empty($statuses)) {
                $q->whereIn('status', $statuses);
            }
        }

        if (!empty($data['staff_id'])) {
            $q->where('staff_id', (int) $data['staff_id']);
        }

        if (!empty($data['service_id'])) {
            $q->where('service_id', (int) $data['service_id']);
        }

        $appointments = $q
            ->orderBy('date', 'asc')
            ->orderBy('starts_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Package session progress is calculated in the backend so the calendar
        // never has to infer session position from a service/package name.
        $packageSessionProgress = $this->buildCalendarPackageSessionProgress($appointments);

        $items = $appointments->map(function (Appointment $a) use ($packageSessionProgress) {
            $service  = $a->service;
            $category = $service?->category;
            $staff    = $a->staff;
            $user     = $a->user;

            $dateStr = $a->date instanceof Carbon
                ? $a->date->toDateString()
                : Carbon::parse($a->date)->toDateString();

            // starts_at in your DB looks like "HH:MM" or "HH:MM:SS"
            $startTime = (string) $a->starts_at;
            $startTime = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;

            $duration = (int) ($a->duration_minutes ?? $service?->duration_minutes ?? 0);

            // build a proper ISO start/end for the calendar UI
            $start = Carbon::parse($dateStr . ' ' . $startTime);
            $end   = (clone $start)->addMinutes(max(0, $duration));

            // paid flag (choose one based on your schema)
            // If appointment has payments relation + sum:
            // $paidSum = $a->payments?->sum('amount') ?? 0;
            // $isPaid  = (float)$paidSum >= (float)($a->price ?? 0);

            // For now use what you already expose:
            $isPaid = null; // we’ll wire it properly once you confirm your payments schema

            return [
                'id'        => $a->id,
                'status'    => $a->status,

                'starts_at' => $start->toIso8601String(),
                'ends_at'   => $end->toIso8601String(),

                'date'      => $dateStr,
                'time'      => substr($startTime, 0, 5), // "HH:MM"
                'duration_minutes' => $duration,

                'price'     => (float) ($a->price ?? 0),
                'is_paid'   => $isPaid,

                'customer' => [
                    'id'    => $user?->id,
                    'name'  => $a->customer_name ?? $user?->name,
                    'phone' => $a->customer_phone ?? $user?->phone,
                ],

                'service' => $service ? [
                    'id'       => $service->id,
                    'name'     => $service->name,
                    'category' => $category?->name,
                ] : null,

                'staff' => $staff ? [
                    'id'   => $staff->id,
                    'name' => $staff->name,
                ] : null,

                // Null for normal single appointments, cancellations/no-shows,
                // or package records that do not represent session-based usage.
                'package_session' => $packageSessionProgress[$a->id] ?? null,
            ];
        })->values();

        return response()->json([
            'from'  => $data['from'],
            'to'    => $data['to'],
            'items' => $items,
        ]);
    }

    /**
     * Build calendar-facing session progress for session-package appointments.
     *
     * Completed appointments use their active package usage ledger entry.
     * Future pending/confirmed appointments are numbered after the sessions that
     * have actually been consumed, then ordered by date/time/id across the whole
     * package (not just the currently visible calendar week).
     */
    private function buildCalendarPackageSessionProgress($calendarAppointments): array
    {
        $packageIds = $calendarAppointments
            ->pluck('service_package_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($packageIds->isEmpty()) {
            return [];
        }

        $packages = ServicePackage::query()
            ->whereIn('id', $packageIds)
            ->get([
                'id',
                'snapshot_total_sessions',
                'remaining_sessions',
                'snapshot_usage_type',
            ])
            ->keyBy('id');

        $sessionLogs = PackageLog::query()
            ->whereIn('service_package_id', $packageIds)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->where('usage_type', Service::USAGE_SESSION)
                    ->orWhere('used_sessions', '>', 0);
            })
            ->orderBy('service_package_id')
            ->orderBy('occurred_on')
            ->orderBy('used_at')
            ->orderBy('id')
            ->get([
                'id',
                'service_package_id',
                'appointment_id',
                'session_number',
                'occurred_on',
                'used_at',
            ]);

        $logsByPackage = $sessionLogs->groupBy('service_package_id');
        $completedNumberByAppointment = [];
        $usedCountByPackage = [];

        foreach ($packageIds as $packageId) {
            $logs = $logsByPackage->get($packageId, collect())->values();
            $usedCountByPackage[$packageId] = $logs->count();

            foreach ($logs as $index => $log) {
                // session_number is the ledger source of truth. The index fallback
                // keeps older migrated logs useful if that field was never backfilled.
                $number = (int) ($log->session_number ?: ($index + 1));

                if ($log->appointment_id) {
                    $completedNumberByAppointment[(int) $log->appointment_id] = $number;
                }
            }
        }

        // Number all active future bookings for the package, even if some of them
        // are outside the current calendar week. This prevents every future visit
        // from incorrectly showing the same "next" session number.
        $today = Carbon::today(config('app.timezone'))->toDateString();

        $futureAppointments = Appointment::query()
            ->whereIn('service_package_id', $packageIds)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereDate('date', '>=', $today)
            ->orderBy('service_package_id')
            ->orderBy('date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'service_package_id', 'date', 'starts_at']);

        $futureNumberByAppointment = [];

        foreach ($futureAppointments->groupBy('service_package_id') as $packageId => $appointments) {
            $usedCount = (int) ($usedCountByPackage[(int) $packageId] ?? 0);

            foreach ($appointments->values() as $index => $appointment) {
                $futureNumberByAppointment[(int) $appointment->id] = $usedCount + $index + 1;
            }
        }

        $result = [];

        foreach ($calendarAppointments as $appointment) {
            if (! $appointment->service_package_id) {
                continue;
            }

            $packageId = (int) $appointment->service_package_id;
            $package = $packages->get($packageId);

            if (! $package) {
                continue;
            }

            $usedCount = (int) ($usedCountByPackage[$packageId] ?? 0);
            $totalSessions = $package->snapshot_total_sessions !== null
                ? (int) $package->snapshot_total_sessions
                : ($package->remaining_sessions !== null
                    ? $usedCount + (int) $package->remaining_sessions
                    : null);

            if (! $totalSessions || $totalSessions < 1) {
                continue;
            }

            $sessionNumber = null;

            if ($appointment->status === Appointment::STATUS_COMPLETED) {
                $sessionNumber = $completedNumberByAppointment[(int) $appointment->id] ?? null;
            } elseif (in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
                $appointmentDate = $appointment->date instanceof Carbon
                    ? $appointment->date->toDateString()
                    : Carbon::parse($appointment->date)->toDateString();

                if ($appointmentDate >= $today) {
                    $sessionNumber = $futureNumberByAppointment[(int) $appointment->id] ?? null;
                }
            }

            if (! $sessionNumber) {
                continue;
            }

            $result[(int) $appointment->id] = [
                'package_id' => $packageId,
                'session_number' => (int) $sessionNumber,
                'total_sessions' => $totalSessions,
                'remaining_sessions' => $package->remaining_sessions !== null
                    ? (int) $package->remaining_sessions
                    : null,
            ];
        }

        return $result;
    }

    public function clientAppointments(Request $request, int $client)
    {
        $request->validate([
            'status'   => ['nullable'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort'     => ['nullable', 'in:newest,oldest,upcoming'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = Appointment::query()
            ->with([
                'service.category',
                'staff',
                'user',
                'package',
            ])
            ->where('user_id', $client);

        if ($request->filled('status')) {
            $statuses = is_array($request->input('status'))
                ? $request->input('status')
                : array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status')))));

            if (!empty($statuses)) {
                $q->whereIn('status', $statuses);
            }
        }

        if ($request->filled('from')) {
            $q->whereDate('date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $q->whereDate('date', '<=', $request->input('to'));
        }

        $sort = $request->input('sort', 'newest');

        if ($sort === 'oldest' || $sort === 'upcoming') {
            $q->orderBy('date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->orderBy('id', 'asc');
        } else {
            $q->orderByDesc('date')
                ->orderByDesc('starts_at')
                ->orderByDesc('id');
        }

        $perPage = min(100, (int) $request->input('per_page', 50));
        $items = $q->paginate($perPage);

        $data = collect($items->items())->map(function (Appointment $a) {
            $service  = $a->service;
            $category = $service?->category;
            $staff    = $a->staff;
            $user     = $a->user;
            $package  = $a->package;

            $dateStr = $a->date instanceof Carbon
                ? $a->date->toDateString()
                : Carbon::parse($a->date)->toDateString();

            $startsAt = (string) $a->starts_at;
            $startsAt = strlen($startsAt) >= 5 ? substr($startsAt, 0, 5) : $startsAt;

            return [
                'id'             => $a->id,
                'reference_code' => $a->reference_code,
                'status'         => $a->status,

                'date'             => $dateStr,
                'starts_at'        => $startsAt,
                'duration_minutes' => (int) ($a->duration_minutes ?? $service?->duration_minutes ?? 0),

                'price'       => (float) ($a->price ?? 0),
                'notes'       => $a->notes,
                'admin_notes' => $a->admin_notes,

                'coverage' => [
                    'type' => $package ? 'package' : 'single',
                    'label' => $package ? 'Covered by package' : 'Single treatment',
                ],

                'customer' => [
                    'id'    => $user?->id,
                    'name'  => $a->customer_name ?? $user?->name,
                    'email' => $a->customer_email ?? $user?->email,
                    'phone' => $a->customer_phone ?? $user?->phone,
                ],

                'service' => $service ? [
                    'id'       => $service->id,
                    'name'     => $service->name,
                    'category' => $category ? [
                        'id'   => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug ?? null,
                    ] : null,
                ] : null,

                'staff' => $staff ? [
                    'id'    => $staff->id,
                    'name'  => $staff->name,
                    'email' => $staff->email,
                    'phone' => $staff->phone,
                ] : null,

                'package' => $package ? [
                    'id'                 => $package->id,
                    'service_id'         => $package->service_id,
                    'service_name'       => $package->service_name,
                    'status'             => $package->status,
                    'remaining_sessions' => $package->remaining_sessions,
                    'remaining_minutes'  => $package->remaining_minutes,
                    'price_total'        => $package->price_total !== null ? (float) $package->price_total : null,
                    'amount_paid'        => (float) ($package->amount_paid ?? 0),
                    'remaining_balance'  => (float) ($package->remaining_balance ?? 0),
                ] : null,

                'created_at' => $a->created_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }


    // 5) Admin create
    public function store(AppointmentStoreRequest $request, AppointmentCompletionService $completionService)
    {
        $data = $request->validated();
        $service = Service::findOrFail($data['service_id']);

        if ($service->usage_type === Service::USAGE_MINUTES || ! $service->is_bookable) {
            abort(422, 'Quantity/minute services are walk-in usage and cannot be booked as appointments.');
        }

        $this->assertStaffCanPerformService($data['staff_id'] ?? null, $service->id);

        $data['duration_minutes'] = $data['duration_minutes'] ?? (int) ($service->duration_minutes ?? 60);
        $data['price'] = $data['price'] ?? (float) ($service->price ?? 0);
        $data['source'] = $data['source'] ?? Appointment::SOURCE_ADMIN_BOOKING;

        $this->assertWithinClinicWorkingHours(
            $data['date'],
            $data['starts_at'],
            (int) $data['duration_minutes'],
        );

        if ($service->usage_type === Service::USAGE_SESSION && empty($data['service_package_id'])) {
            abort(422, 'Select the exact client package before booking a package session.');
        }

        if (! empty($data['service_package_id'])) {
            if (empty($data['user_id'])) {
                abort(422, 'A registered client is required when using a package.');
            }

            $package = ServicePackage::findOrFail((int) $data['service_package_id']);
            $this->preparePackageForAppointment(
                package: $package,
                userId: (int) $data['user_id'],
                serviceId: (int) $data['service_id'],
                staffId: $data['staff_id'] ?? null,
                date: $data['date'],
                enforceInterval: true,
            );
        }

        $this->assertNoOverlap(
            $data['date'],
            $data['starts_at'],
            (int) $data['duration_minutes'],
            (int) $data['service_id'],
            $data['staff_id'] ?? null,
            null,
        );

        $requestedStatus = $data['status'];
        if ($requestedStatus === Appointment::STATUS_COMPLETED) {
            $data['status'] = Appointment::STATUS_CONFIRMED;
        }

        $data['reference_code'] = $this->newReferenceCode();

        $appointment = DB::transaction(function () use ($data, $requestedStatus, $completionService, $request) {
            $appointment = Appointment::create($data);

            if ($requestedStatus === Appointment::STATUS_COMPLETED) {
                $completionService->complete(
                    appointment: $appointment,
                    actorUserId: optional($request->user())->id,
                    note: $data['notes'] ?? null,
                    source: PackageLog::SOURCE_AUTOMATIC,
                );
            }

            return $appointment->refresh();
        });

        return response()->json([
            'message' => 'Appointment created',
            'appointment' => $appointment->load(['service', 'package']),
        ], 201);
    }

    public function update(
        AppointmentUpdateRequest $request,
        Appointment $appointment,
        AppointmentCompletionService $completionService,
        AdminAppointmentUpdateService $appointmentUpdateService,
    ) {
        $data = $request->validated();

        $structuralKeys = [
            'service_id',
            'service_package_id',
            'staff_id',
            'date',
            'starts_at',
        ];

        $hasStructuralEdit = collect($structuralKeys)
            ->contains(fn (string $key) => array_key_exists($key, $data));

        if ($hasStructuralEdit) {
            if (array_key_exists('status', $data)) {
                abort(422, 'Edit the booking details and appointment status separately so each change is audited clearly.');
            }

            $admin = $request->user();
            if (! $admin) {
                abort(401, 'Authentication required.');
            }

            $result = $appointmentUpdateService->update(
                appointment: $appointment,
                data: $data,
                admin: $admin,
            );

            $updatedAppointment = $result['appointment']->toArray();
            $updatedAppointment['date'] = $result['appointment']->date instanceof Carbon
                ? $result['appointment']->date->toDateString()
                : Carbon::parse($result['appointment']->date)->toDateString();

            return response()->json([
                'message' => 'Appointment updated',
                'data' => [
                    'appointment' => $updatedAppointment,
                    'warnings' => $result['warnings'],
                    'next_allowed_date' => $result['next_allowed_date'],
                ],
            ]);
        }

        // Backwards-compatible status/notes-only behavior. The dedicated
        // /status endpoint remains the preferred path for status changes and
        // is also the only path used to reverse a completed visit safely.
        $requestedStatus = $data['status'] ?? null;

        if ($requestedStatus !== null) {
            $this->assertTransitionAllowed($appointment->status, $requestedStatus);
        }

        if ($requestedStatus === Appointment::STATUS_COMPLETED) {
            $completionService->complete(
                appointment: $appointment,
                actorUserId: optional($request->user())->id,
                note: $data['notes'] ?? null,
                source: PackageLog::SOURCE_AUTOMATIC,
            );
        } else {
            $updates = [];
            if (array_key_exists('status', $data)) {
                $updates['status'] = $data['status'];
            }
            if (array_key_exists('notes', $data)) {
                $updates['notes'] = $data['notes'];
            }

            if ($updates !== []) {
                $appointment->fill($updates)->save();
            }
        }

        return response()->json([
            'message' => 'Appointment updated',
            'appointment' => $appointment->fresh()->load(['service', 'package']),
        ]);
    }

    public function destroy(Appointment $appointment)
    {
        if ($appointment->status === Appointment::STATUS_COMPLETED) {
            abort(422, 'Correct the completed appointment status with a reason before deleting it, so package usage can be restored and audited.');
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted']);
    }

    // ---------- Helpers ----------

    protected function newReferenceCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (Appointment::where('reference_code', $code)->exists());
        return $code;
    }

    protected function assertTransitionAllowed(string $from, string $to): void
    {
        // Allowed transitions:
        // pending   → confirmed|cancelled
        // confirmed → completed|cancelled|no_show
        // completed/no_show/cancelled → final (no further status changes)
        $map = [
            'pending'   => ['confirmed','cancelled'],
            'confirmed' => ['completed','cancelled','no_show'],
            'completed' => [],
            'no_show'   => [],
            'cancelled' => [],
        ];

        if (!isset($map[$from]) || !in_array($to, $map[$from], true)) {
            abort(422, "Invalid status transition: $from → $to");
        }
    }

    protected function normalizeStartsAt(string $startsAt): string
    {
        return strlen($startsAt) === 5 ? $startsAt . ':00' : $startsAt;
    }

    protected function assertWithinClinicWorkingHours(
        string $date,
        string $startsAt,
        int $durationMinutes
    ): void {
        $dateOnly = Carbon::parse($date)->toDateString();
        $startsAtNorm = $this->normalizeStartsAt($startsAt);

        $start = Carbon::parse($dateOnly . ' ' . $startsAtNorm);
        $end = (clone $start)->addMinutes($durationMinutes);

        $hours = self::CLINIC_WORKING_HOURS[$start->dayOfWeek] ?? null;

        if (!$hours) {
            abort(422, 'The clinic is closed on this day.');
        }

        $open = Carbon::parse($dateOnly . ' ' . $hours[0] . ':00');
        $close = Carbon::parse($dateOnly . ' ' . $hours[1] . ':00');

        if ($start->lt($open) || $end->gt($close)) {
            abort(422, 'The selected time is outside clinic working hours.');
        }
    }

    protected function assertPackageMatchesService(ServicePackage $package, int $serviceId): void
    {
        if ((int) $package->service_id !== (int) $serviceId) {
            abort(422, 'This package can only be used for its own service. Select the correct package or create a single treatment appointment.');
        }
    }

    protected function assertStaffCanPerformService(?int $staffId, int $serviceId): void
    {
        if (! $staffId) {
            return;
        }

        $qualified = Staff::query()
            ->whereKey($staffId)
            ->where('is_active', true)
            ->whereHas('services', fn ($query) => $query->where('services.id', $serviceId))
            ->exists();

        if (! $qualified) {
            abort(422, 'The selected staff member is not qualified or active for this service.');
        }
    }

    protected function preparePackageForAppointment(
        ServicePackage $package,
        int $userId,
        int $serviceId,
        ?int $staffId,
        string $date,
        bool $enforceInterval = true,
    ): void {
        if ((int) $package->user_id !== $userId) {
            abort(422, 'The selected package does not belong to this client.');
        }

        $this->assertPackageMatchesService($package, $serviceId);

        try {
            $package->assertUsableOn($date);
        } catch (\LogicException $exception) {
            abort(422, $exception->getMessage());
        }

        if (! $package->isSessionsType()) {
            abort(422, 'Quantity/minute packages cannot be used for appointments.');
        }

        if ((int) $package->remaining_sessions <= 0) {
            abort(422, 'The selected package has no sessions remaining.');
        }

        if ($enforceInterval && $package->next_allowed_date && Carbon::parse($date)->lt(Carbon::parse($package->next_allowed_date))) {
            abort(422, "The next package session is allowed from {$package->next_allowed_date}.");
        }

        if ($package->staffPolicy() !== Service::STAFF_SAME) {
            return;
        }

        if (! $staffId) {
            abort(422, 'A staff member is required for a same-staff package.');
        }

        $hasCompletedSession = $package->activeUsageLogs()
            ->where('usage_type', Service::USAGE_SESSION)
            ->exists();

        if ($hasCompletedSession && (int) $package->assigned_staff_id !== (int) $staffId) {
            abort(422, 'This package is locked to its assigned staff member.');
        }

        if (! $hasCompletedSession && (int) $package->assigned_staff_id !== (int) $staffId) {
            $package->assigned_staff_id = $staffId;
            $package->save();
        }
    }

    /**
     * Overlap rule (robust):
     * - Accepts $date as 'YYYY-MM-DD' OR full datetime; normalizes to date-only
     * - Accepts $startsAt as 'HH:MM' or 'HH:MM:SS'; normalizes to HH:MM:SS
     * - Blocks overlap if another appt intersects and (same service) OR (same staff when provided)
     */
    protected function assertNoOverlap(
        string $date,
        string $startsAt,
        int $durationMinutes,
        int $serviceId,
        ?int $staffId = null,
        ?int $ignoreId = null
    ): void {
        // Normalize date-only even if a full datetime is passed
        $dateOnly = Carbon::parse($date)->toDateString();

        // Normalize time to HH:MM:SS (accept both 09:30 and 09:30:00)
        $startsAtNorm = strlen($startsAt) === 5 ? $startsAt . ':00' : $startsAt;

        $start = Carbon::parse("$dateOnly $startsAtNorm");
        $end   = (clone $start)->addMinutes($durationMinutes);

        $candidates = Appointment::query()
            ->whereDate('date', $dateOnly)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->whereIn('status', ['pending','confirmed','completed'])
            ->where(function ($q) use ($serviceId, $staffId) {
                $q->where('service_id', $serviceId);
                if ($staffId) {
                    $q->orWhere('staff_id', $staffId);
                }
            })
            ->get(['date','starts_at','duration_minutes']);

        foreach ($candidates as $a) {
            $dateValue = $a->date instanceof Carbon
                ? $a->date
                : Carbon::parse($a->date);

            $aStartsAt = strlen($a->starts_at) === 5 ? $a->starts_at . ':00' : $a->starts_at;

            $aStart = (clone $dateValue)->setTimeFromTimeString($aStartsAt);
            $aEnd   = (clone $aStart)->addMinutes((int) $a->duration_minutes);

            if ($aStart->lt($end) && $start->lt($aEnd)) {
                abort(422, 'Time slot overlaps an existing appointment.');
            }
        }
    }

    public function storeClientManualAppointment(
        Request $request,
        int $client,
        AppointmentCompletionService $completionService,
    ) {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_package_id' => ['nullable', 'integer', 'exists:service_packages,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'status' => ['nullable', 'in:pending,confirmed,completed,cancelled,no_show,no-show'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['nullable', 'in:paid,partial,unpaid'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'source' => ['nullable', 'in:admin_booking,manual_import'],
        ]);

        $source = $data['source'] ?? Appointment::SOURCE_MANUAL_IMPORT;
        $isAdminBooking = $source === Appointment::SOURCE_ADMIN_BOOKING;
        $dateOnly = Carbon::parse($data['date'])->toDateString();

        if (! $isAdminBooking && Carbon::parse($dateOnly)->gt(Carbon::today())) {
            abort(422, 'Past visit import cannot be used for future appointments.');
        }

        if ($isAdminBooking && Carbon::parse($dateOnly)->lt(Carbon::today())) {
            abort(422, 'Admin booking cannot be created in the past.');
        }

        $user = User::findOrFail($client);
        $service = Service::findOrFail((int) $data['service_id']);

        if ($service->usage_type === Service::USAGE_MINUTES || ! $service->is_bookable) {
            abort(422, 'Quantity/minute services must be recorded as package usage, not appointments.');
        }

        $this->assertStaffCanPerformService($data['staff_id'] ?? null, $service->id);

        $status = $data['status'] ?? ($isAdminBooking
            ? Appointment::STATUS_CONFIRMED
            : Appointment::STATUS_COMPLETED);
        $status = $status === 'no-show' ? Appointment::STATUS_NO_SHOW : $status;

        $startsAt = $this->normalizeStartsAt(
            $data['starts_at'] ?? ($isAdminBooking ? '09:00' : '00:00'),
        );
        $durationMinutes = (int) ($service->duration_minutes ?? 60);

        if ($service->usage_type === Service::USAGE_SESSION && empty($data['service_package_id'])) {
            abort(422, 'Select the exact client package before saving a package session.');
        }

        $package = null;
        if (! empty($data['service_package_id'])) {
            $package = ServicePackage::findOrFail((int) $data['service_package_id']);
            $this->preparePackageForAppointment(
                package: $package,
                userId: $user->id,
                serviceId: $service->id,
                staffId: $data['staff_id'] ?? null,
                date: $dateOnly,
                enforceInterval: $isAdminBooking,
            );
        }

        if ($isAdminBooking) {
            $this->assertWithinClinicWorkingHours($dateOnly, $startsAt, $durationMinutes);
            $this->assertNoOverlap(
                $dateOnly,
                $startsAt,
                $durationMinutes,
                (int) $service->id,
                $data['staff_id'] ?? null,
                null,
            );
        }

        $payload = [
            'user_id' => $user->id,
            'service_id' => $service->id,
            'service_package_id' => $package?->id,
            'staff_id' => $data['staff_id'] ?? null,
            'date' => $dateOnly,
            'starts_at' => $startsAt,
            'duration_minutes' => $durationMinutes,
            'price' => (float) ($data['price'] ?? $service->price ?? 0),
            'status' => $status === Appointment::STATUS_COMPLETED
                ? Appointment::STATUS_CONFIRMED
                : $status,
            'source' => $source,
            'reference_code' => $this->newReferenceCode(),
            'notes' => $data['notes'] ?? null,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => $user->phone,
        ];

        if (Schema::hasColumn('appointments', 'payment_status')) {
            $payload['payment_status'] = $data['payment_status'] ?? null;
        }

        if (Schema::hasColumn('appointments', 'admin_notes')) {
            $payload['admin_notes'] = $data['notes'] ?? null;
        }

        $appointment = DB::transaction(function () use (
            $payload,
            $status,
            $source,
            $isAdminBooking,
            $data,
            $package,
            $request,
            $completionService,
        ) {
            $appointment = new Appointment();
            $appointment->forceFill($payload);
            $appointment->save();

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => optional($request->user())->id,
                'action' => $isAdminBooking ? 'admin_booking_created' : 'manual_import_created',
                'meta' => [
                    'source' => $source,
                    'payment_status' => $data['payment_status'] ?? null,
                    'service_package_id' => $package?->id,
                ],
            ]);

            if ($status === Appointment::STATUS_COMPLETED) {
                $completionService->complete(
                    appointment: $appointment,
                    actorUserId: optional($request->user())->id,
                    note: $data['notes'] ?? null,
                    source: $isAdminBooking
                        ? PackageLog::SOURCE_AUTOMATIC
                        : PackageLog::SOURCE_IMPORTED,
                );
            }

            return $appointment->refresh();
        });

        $appointment->load(['service.category', 'staff', 'user', 'package']);

        return response()->json([
            'message' => $isAdminBooking ? 'Appointment booked' : 'Manual visit added',
            'data' => $appointment,
        ], 201);
    }
     
    public function showBooking(Request $request, Appointment $appointment)
    {
        $appointment->loadMissing([
            'service.category',
            'staff',
            'user',
            'package.logs',
            'package.payments',
            'payments.staff',
            'payments.admin',
            'payments.voidedBy',
            'logs.user',
        ]);

        $service  = $appointment->service;
        $category = $service?->category;
        $staff    = $appointment->staff;
        $user     = $appointment->user;
        $package  = $appointment->package;

        // ---------------------------------------------------------
        // Payment summary
        // ---------------------------------------------------------
        // Single treatments are priced in EUR in the current schema.
        // Payments can still be recorded as EUR cash, MKD cash, or MKD card.
        // Always normalize to MKD for arithmetic, then convert back to the
        // booking currency for display. Voided payments never reduce balance.
        $appointmentPrice = (float) ($appointment->price ?? 0.0);
        $appointmentPriceMkd = round($appointmentPrice * ServicePackage::EUR_TO_MKD, 2);

        $appointmentPayments = $appointment->payments
            ->sortByDesc('id')
            ->values();

        $appointmentPaidMkd = round(
            $appointmentPayments
                ->whereNull('voided_at')
                ->sum(fn (PackagePayment $payment) => $this->paymentAmountToMkd($payment)),
            2,
        );

        $appointmentRemainingMkd = round(
            max($appointmentPriceMkd - $appointmentPaidMkd, 0),
            2,
        );
        $appointmentPaid = round($appointmentPaidMkd / ServicePackage::EUR_TO_MKD, 2);
        $appointmentRemaining = round($appointmentRemainingMkd / ServicePackage::EUR_TO_MKD, 2);

        $packageTotal = $package && $package->price_total !== null
            ? (float) $package->price_total
            : ($package && $package->price_paid !== null ? (float) $package->price_paid : null);
        $packageTotalMkd = $package ? (float) $package->priceTotalMkd() : null;
        $packagePaid = $package ? (float) ($package->amount_paid ?? 0) : null;
        $packagePaidMkd = $package ? (float) ($package->amount_paid_mkd ?? 0) : null;
        $packageRemaining = $package ? (float) ($package->remaining_to_pay ?? 0) : null;
        $packageRemainingMkd = $package ? (float) ($package->remaining_to_pay_mkd ?? 0) : null;

        $isPackageBooking = $package !== null;
        $paymentCurrency = $isPackageBooking ? $package->packageCurrency() : 'EUR';
        $totalPrice = $isPackageBooking ? (float) ($packageTotal ?? 0) : $appointmentPrice;
        $amountPaid = $isPackageBooking ? (float) ($packagePaid ?? 0) : $appointmentPaid;
        $remainingPrice = $isPackageBooking ? (float) ($packageRemaining ?? 0) : $appointmentRemaining;
        $totalPriceMkd = $isPackageBooking ? (float) ($packageTotalMkd ?? 0) : $appointmentPriceMkd;
        $amountPaidMkd = $isPackageBooking ? (float) ($packagePaidMkd ?? 0) : $appointmentPaidMkd;
        $remainingPriceMkd = $isPackageBooking ? (float) ($packageRemainingMkd ?? 0) : $appointmentRemainingMkd;

        $paymentStatus = $remainingPriceMkd <= 0.01
            ? 'paid'
            : ($amountPaidMkd > 0.01 ? 'partial' : 'unpaid');

        $presentAppointmentPayment = function (PackagePayment $payment): array {
            $recordedBy = null;

            if ($payment->staff) {
                $recordedBy = [
                    'type' => 'staff',
                    'id' => $payment->staff->id,
                    'name' => $payment->staff->name,
                ];
            } elseif ($payment->admin) {
                $recordedBy = [
                    'type' => 'admin',
                    'id' => $payment->admin->id,
                    'name' => $payment->admin->name,
                ];
            }

            return [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                'amount_mkd' => $payment->amount_mkd !== null
                    ? (float) $payment->amount_mkd
                    : $this->paymentAmountToMkd($payment),
                'note' => $payment->notes,
                'recorded_by' => $recordedBy,
                'created_at' => $payment->created_at?->toIso8601String(),
                'is_voided' => $payment->voided_at !== null,
                'voided_at' => $payment->voided_at?->toIso8601String(),
                'void_reason' => $payment->void_reason,
            ];
        };

        return response()->json([
            'id'             => $appointment->id,
            'reference_code' => $appointment->reference_code,
            'status'         => $appointment->status,

            'date' => $appointment->date instanceof \Illuminate\Support\Carbon
                ? $appointment->date->toDateString()
                : \Illuminate\Support\Carbon::parse($appointment->date)->toDateString(),

            'starts_at'        => (string) $appointment->starts_at,
            'duration_minutes' => (int) ($appointment->duration_minutes ?? $service?->duration_minutes ?? 60),

            // Current booking price fields retained for frontend compatibility.
            'price'           => $appointmentPrice,
            'total_price'     => $totalPrice,
            'remaining_price' => $remainingPrice,
            'amount_paid'     => $amountPaid,
            'currency'        => $paymentCurrency,
            'payment_status'  => $paymentStatus,
            'is_paid'         => $paymentStatus === 'paid',

            // Explicit source-of-truth payment summary for the booking details UI.
            'payment_summary' => [
                'source' => $isPackageBooking ? 'package' : 'appointment',
                'currency' => $paymentCurrency,
                'total' => $totalPrice,
                'paid' => $amountPaid,
                'remaining' => $remainingPrice,
                'total_mkd' => $totalPriceMkd,
                'paid_mkd' => $amountPaidMkd,
                'remaining_mkd' => $remainingPriceMkd,
                'status' => $paymentStatus,
            ],

            // Appointment payments are intentionally separate from package payments.
            // For package bookings this collection should normally be empty.
            'payments' => $appointmentPayments
                ->map($presentAppointmentPayment)
                ->values(),

            'notes'       => $appointment->notes,
            'admin_notes' => $appointment->admin_notes,

            'customer' => [
                'id'    => $user?->id,
                'name'  => $appointment->customer_name ?? $user?->name,
                'email' => $appointment->customer_email ?? $user?->email,
                'phone' => $appointment->customer_phone ?? $user?->phone,
            ],

            'service' => $service ? [
                'id'               => $service->id,
                'name'             => $service->name,
                'slug'             => $service->slug,
                'duration_minutes' => (int) ($service->duration_minutes ?? 0),
                'base_price'       => (float) ($service->price ?? 0),
                'category'         => $category ? [
                    'id'   => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug ?? null,
                ] : null,
            ] : null,

            'staff' => $staff ? [
                'id'    => $staff->id,
                'name'  => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
            ] : null,

            'package' => $package ? [
                'id'           => $package->id,
                'service_id'   => $package->service_id,
                'service_name' => $package->service_name,
                'status'       => $package->status,
                'currency'     => $package->packageCurrency(),

                'price_total' => $package->price_total !== null ? (float) $package->price_total : null,
                'price_paid'  => $package->price_paid !== null ? (float) $package->price_paid : null,

                'amount_paid'          => (float) ($package->amount_paid ?? 0),
                'amount_paid_mkd'      => (float) ($package->amount_paid_mkd ?? 0),
                'remaining_to_pay'     => (float) ($package->remaining_to_pay ?? 0),
                'remaining_to_pay_mkd' => (float) ($package->remaining_to_pay_mkd ?? 0),
                'remaining_balance'    => (float) ($package->remaining_to_pay ?? 0),

                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes'  => $package->remaining_minutes,
                'starts_on'          => optional($package->starts_on)->toDateString(),
                'expires_on'         => optional($package->expires_on)->toDateString(),

                'payments' => $package->payments()
                    ->whereNull('voided_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(function ($p) {
                        return [
                            'id'             => $p->id,
                            'amount'         => (float) $p->amount,
                            'currency'       => $p->currency,
                            'method'         => $p->method,
                            'note'           => $p->notes,
                            'appointment_id' => $p->appointment_id,
                            'staff_id'       => $p->staff_id,
                            'admin_id'       => $p->admin_id,
                            'created_at'     => $p->created_at?->toIso8601String(),
                        ];
                    })
                    ->values(),

                'usage_logs' => $package->logs->map(function ($log) {
                    return [
                        'id'            => $log->id,
                        'used_sessions' => $log->used_sessions,
                        'used_minutes'  => $log->used_minutes,
                        'used_at'       => optional($log->used_at)?->toDateTimeString(),
                        'staff_id'      => $log->staff_id,
                        'note'          => $log->note,
                    ];
                })->values(),
            ] : null,

            'created_at' => $appointment->created_at?->toIso8601String(),
            'updated_at' => $appointment->updated_at?->toIso8601String(),

            'logs' => $appointment->logs->map(function (AppointmentLog $log) {
                return [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'details'    => $log->details,
                    'meta'       => $log->meta,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'user'       => $log->user ? [
                        'id'    => $log->user->id,
                        'name'  => $log->user->name,
                        'email' => $log->user->email,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    private function paymentAmountToMkd(PackagePayment $payment): float
    {
        if ($payment->amount_mkd !== null) {
            return (float) $payment->amount_mkd;
        }

        $amount = (float) $payment->amount;
        $currency = strtoupper($payment->currency ?: 'EUR');

        if ($currency === 'EUR') {
            $rate = (float) ($payment->exchange_rate ?: ServicePackage::EUR_TO_MKD);
            return round($amount * $rate, 2);
        }

        return round($amount, 2);
    }


    public function stats(Request $request)
{
    // Normalize timezone (optional, aligns “today/now” with clinic)
    date_default_timezone_set(config('clinic.timezone', config('app.timezone')));

    $today     = Carbon::today();
    $tomorrow  = (clone $today)->addDay();
    $now       = Carbon::now();

    // Optional filters
    $serviceId = $request->integer('service_id');
    $staffId   = $request->filled('staff_id') ? (int) $request->input('staff_id') : null;

    $base = Appointment::query()
        ->when($serviceId, fn($q) => $q->where('service_id', $serviceId))
        ->when($staffId,   fn($q) => $q->where('staff_id', $staffId));

    // ----- Counts by status -----
    $rawCounts = (clone $base)
        ->selectRaw('status, COUNT(*) as count')
        ->groupBy('status')
        ->pluck('count', 'status')
        ->all();

    $allStatuses = ['pending','confirmed','cancelled','completed','no_show'];
    $counts = [];
    foreach ($allStatuses as $st) {
        $counts[$st] = (int) ($rawCounts[$st] ?? 0);
    }

    // ----- Today revenue (confirmed + completed, respecting filters) -----
    $todayRevenue = (clone $base)
        ->whereDate('date', $today->toDateString())
        ->whereIn('status', ['confirmed', 'completed'])
        ->sum('price');

    // ----- Upcoming today (pending/confirmed) -----
    $todayItems = (clone $base)
        ->with(['service:id,name'])
        ->whereDate('date', $today->toDateString())
        ->whereIn('status', ['pending','confirmed'])
        ->where('starts_at', '>=', $now->format('H:i:s'))
        ->orderBy('starts_at', 'asc')
        ->limit(10)
        ->get(['id','service_id','date','starts_at','customer_name','status']);

    // ----- Upcoming tomorrow (all day, pending/confirmed) -----
    $tomorrowItems = (clone $base)
        ->with(['service:id,name'])
        ->whereDate('date', $tomorrow->toDateString())
        ->whereIn('status', ['pending','confirmed'])
        ->orderBy('starts_at', 'asc')
        ->limit(10)
        ->get(['id','service_id','date','starts_at','customer_name','status']);

    $mapLite = function ($col) {
        return $col->map(function ($a) {
            $dateStr = $a->date instanceof Carbon
                ? $a->date->toDateString()
                : Carbon::parse($a->date)->toDateString();

            return [
                'id'            => $a->id,
                'service'       => [
                    'id'   => $a->service_id,
                    'name' => optional($a->service)->name,
                ],
                'date'          => $dateStr,
                'time'          => $a->starts_at,
                'customer_name' => $a->customer_name,
                'status'        => $a->status,
            ];
        })->values();
    };

    return response()->json([
        'filters' => [
            'service_id' => $serviceId,
            'staff_id'   => $staffId,
        ],
        'counts_by_status' => $counts,
        'today_revenue'    => (float) $todayRevenue, // 💰 added here
        'upcoming' => [
            'today' => [
                'date'  => $today->toDateString(),
                'total' => (clone $base)
                    ->whereDate('date', $today->toDateString())
                    ->whereIn('status', ['pending','confirmed'])
                    ->where('starts_at', '>=', $now->format('H:i:s'))
                    ->count(),
                'items' => $mapLite($todayItems),
            ],
            'tomorrow' => [
                'date'  => $tomorrow->toDateString(),
                'total' => (clone $base)
                    ->whereDate('date', $tomorrow->toDateString())
                    ->whereIn('status', ['pending','confirmed'])
                    ->count(),
                'items' => $mapLite($tomorrowItems),
            ],
        ],
    ]);
}



    /**
     * PATCH /api/v1/admin/appointments/{appointment}/assign
     */
    public function assign(AssignStaffRequest $request, Appointment $appointment)
    {
        $newStaffId = (int) $request->validated()['staff_id'];

        if ($appointment->staff_id === $newStaffId) {
            return response()->json([
                'message' => 'Staff already assigned',
                'data'    => $appointment->only(['id','staff_id']),
            ]);
        }

        $service  = Service::findOrFail($appointment->service_id);

        // Normalize inputs for guard
        $dateOnly = Carbon::parse($appointment->date)->toDateString();
        $startsAt = $appointment->starts_at ?? $appointment->time;
        $startsAt = strlen($startsAt) === 5 ? $startsAt . ':00' : $startsAt;

        $this->assertNoOverlap(
            $dateOnly,
            $startsAt,
            (int) $service->duration_minutes,
            (int) $service->id,
            $newStaffId,
            (int) $appointment->id
        );

        $oldStaffId = $appointment->staff_id;
        $appointment->update(['staff_id' => $newStaffId]);

        AppointmentLog::create([
            'appointment_id' => $appointment->id,
            'user_id'        => optional(request()->user())->id,
            'action'         => $oldStaffId ? 'reassigned' : 'assigned',
            'meta'           => ['old_staff_id' => $oldStaffId, 'new_staff_id' => $newStaffId],
        ]);

        return response()->json([
            'message' => 'Staff assigned successfully',
            'data'    => ['id' => $appointment->id, 'staff_id' => $appointment->staff_id],
        ]);
    }

    /**
     * GET /api/v1/admin/appointments/{appointment}/logs
     */
    public function logs(Request $request, Appointment $appointment)
    {
        // Filters
        $request->validate([
            'action'   => ['sometimes', 'string', 'max:100'],
            'since'    => ['sometimes', 'date'],  // ISO 8601 or Y-m-d
            'until'    => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) $request->query('per_page', 20);

        $q = $appointment->logs()->with(['user:id,name,email']);

        if ($action = $request->query('action')) {
            $q->where('action', $action);
        }
        if ($since = $request->query('since')) {
            $q->where('created_at', '>=', Carbon::parse($since));
        }
        if ($until = $request->query('until')) {
            $q->where('created_at', '<=', Carbon::parse($until));
        }

        $logs = $q->paginate($perPage);

        // Shape the response
        $data = $logs->getCollection()->map(function ($log) {
            return [
                'id'           => $log->id,
                'action'       => $log->action,
                'details'      => $log->details,
                'performed_by' => $log->user ? [
                    'id'    => $log->user->id,
                    'name'  => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'meta'         => $log->meta ?? null, // optional: before/after diffs
                'created_at'   => $log->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'appointment_id' => $appointment->id,
            'count'          => $logs->total(),
            'page'           => $logs->currentPage(),
            'per_page'       => $logs->perPage(),
            'data'           => $data,
        ]);
    }

    /**
     * PATCH /api/v1/admin/appointments/{appointment}/status
     * Allows admin to set any valid status (pending|confirmed|cancelled|completed|no_show)
     */
    public function updateStatus(
        AdminUpdateAppointmentStatusRequest $request,
        Appointment $appointment,
        AppointmentCompletionService $completionService,
    ) {
        $validated = $request->validated();
        $to = $validated['status'];
        $from = $appointment->status;

        if ($from === $to) {
            return response()->json([
                'message' => 'Status unchanged.',
                'data' => ['id' => $appointment->id, 'status' => $appointment->status],
            ]);
        }

        if ($to === Appointment::STATUS_CONFIRMED && $from !== Appointment::STATUS_COMPLETED) {
            $service = Service::findOrFail($appointment->service_id);
            $dateOnly = Carbon::parse($appointment->date)->toDateString();
            $startsAt = $this->normalizeStartsAt($appointment->starts_at ?? $appointment->time);
            $durationMinutes = (int) ($appointment->duration_minutes ?? $service->duration_minutes ?? 60);

            $this->assertWithinClinicWorkingHours($dateOnly, $startsAt, $durationMinutes);
            $this->assertNoOverlap(
                $dateOnly,
                $startsAt,
                $durationMinutes,
                (int) $service->id,
                $appointment->staff_id,
                (int) $appointment->id,
            );
        }

        if ($to === Appointment::STATUS_COMPLETED) {
            $completionService->complete(
                appointment: $appointment,
                actorUserId: optional($request->user())->id,
                note: $validated['notes'] ?? null,
                source: $appointment->source === Appointment::SOURCE_MANUAL_IMPORT
                    ? PackageLog::SOURCE_IMPORTED
                    : PackageLog::SOURCE_AUTOMATIC,
            );
        } elseif ($from === Appointment::STATUS_COMPLETED) {
            $reason = trim((string) ($validated['reason'] ?? ''));
            if ($reason === '') {
                abort(422, 'A reason is required when correcting a completed appointment.');
            }

            $completionService->reverseCompletion(
                appointment: $appointment,
                targetStatus: $to,
                actorUserId: optional($request->user())->id,
                reason: $reason,
            );

            if (array_key_exists('notes', $validated)) {
                $appointment->refresh();
                $appointment->notes = $validated['notes'];
                $appointment->save();
            }
        } else {
            DB::transaction(function () use ($request, $appointment, $from, $to, $validated) {
                $appointment->status = $to;
                if (array_key_exists('notes', $validated)) {
                    $appointment->notes = $validated['notes'];
                }
                $appointment->save();

                AppointmentLog::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => optional($request->user())->id,
                    'action' => 'status_changed',
                    'meta' => ['from' => $from, 'to' => $to],
                ]);
            });
        }

        $appointment->refresh();

        return response()->json([
            'message' => "Appointment status updated to {$to}.",
            'data' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
                'date' => $appointment->date,
                'starts_at' => $appointment->starts_at,
                'staff_id' => $appointment->staff_id,
                'service_id' => $appointment->service_id,
            ],
        ]);
    }

    public function updateNotes(Request $request, Appointment $appointment)
    {
        if (!Schema::hasColumn('appointments', 'admin_notes')) {
            return response()->json([
                'ok' => false,
                'message' => 'The admin_notes field is not available on this installation.',
            ], 422);
        }

        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $appointment->update(['admin_notes' => $data['admin_notes']]);

        AppointmentLog::create([
            'appointment_id' => $appointment->id,
            'action'         => 'admin_notes_updated',
            'details'        => 'Admin updated internal notes.',
        ]);

        return response()->json(['ok' => true, 'message' => 'Notes updated']);
    }

    // ------------------------------------------------------------------
    // NEW: ADMIN DASHBOARD / BOOKINGS ENDPOINTS FOR FRONTEND
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/admin/bookings
     * Flat list used by dashboard:
     * id, customer_name, phone, status, date, starts_at,
     * service_id, service_name, category_id, category_name,
     * total_price, staff_name, user_name
     */
    public function bookings(Request $request)
    {
        $appointments = Appointment::with([
                'service.category',
                'staff',
                'user',
            ])
            ->orderByDesc('date')
            ->orderByDesc('starts_at')
            ->limit(500) // safety cap; adjust if needed
            ->get();

        $data = $appointments->map(function (Appointment $a) {
            $service  = $a->service;
            $category = $service?->category;
            $staff    = $a->staff;
            $user     = $a->user;

            return [
                'id'             => $a->id,
                'customer_name'  => $a->customer_name ?? $user?->name,
                'phone'          => $a->customer_phone ?? $user?->phone,
                'status'         => $a->status,
                'date'           => $a->date instanceof Carbon
                                        ? $a->date->toDateString()
                                        : (string) $a->date,
                'starts_at'      => $a->starts_at instanceof Carbon
                                        ? $a->starts_at->format('H:i')
                                        : (string) $a->starts_at,
                'service_id'     => $service?->id,
                'service_name'   => $service?->name,
                'category_id'    => $category?->id,
                'category_name'  => $category?->name,
                'total_price'    => $a->total_price ?? $a->price ?? 0,
                'staff_name'     => $staff?->name,
                'user_name'      => $user?->name,
            ];
        });

        return response()->json($data);
    }

    /**
     * GET /api/v1/admin/bookings/filter
     * Optional server-side filtering:
     * search, status, service_id, category_id, date_from, date_to, sort
     */
    public function filterBookings(Request $request)
    {
        $query = Appointment::query()
            ->with(['service.category', 'staff', 'user']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  })
                  ->orWhereHas('service', function ($s) use ($search) {
                      $s->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($serviceId = $request->query('service_id')) {
            $query->where('service_id', $serviceId);
        }

        if ($categoryId = $request->query('category_id')) {
            $query->whereHas('service', function ($q) use ($categoryId) {
                $q->where('service_category_id', $categoryId);
            });
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('date', '<=', $to);
        }

        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'oldest'     => $query->orderBy('date')->orderBy('starts_at'),
            'price_high' => $query->orderByDesc('total_price')->orderByDesc('price'),
            'price_low'  => $query->orderBy('total_price')->orderBy('price'),
            default      => $query->orderByDesc('date')->orderByDesc('starts_at'),
        };

        $perPage   = (int) $request->query('per_page', 20);
        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(function (Appointment $a) {
            $service = $a->service;
            return [
                'id'            => $a->id,
                'customer_name' => $a->customer_name ?? $a->user?->name,
                'service_name'  => $service?->name,
                'status'        => $a->status,
                'date'          => (string) $a->date,
                'starts_at'     => (string) $a->starts_at,
                'total_price'   => $a->total_price ?? $a->price ?? 0,
            ];
        });

        return response()->json([
            'data'  => $data,
            'total' => $paginator->total(),
        ]);
    }

    /**
     * GET /api/v1/admin/stats
     * Lightweight dashboard stats:
     * today_total, today_pending, today_confirmed, today_completed, today_revenue, recent[]
     */
    /**
 * GET /api/v1/admin/stats
 * Lightweight dashboard stats:
 * today_total, today_pending, today_confirmed, today_completed, today_revenue, recent[]
 */
public function dashboardStats(Request $request)
{
    date_default_timezone_set(config('clinic.timezone', config('app.timezone')));

    $today = Carbon::today();

    $baseToday = Appointment::whereDate('date', $today->toDateString());

    $todayTotal     = (clone $baseToday)->count();
    $todayPending   = (clone $baseToday)->where('status', 'pending')->count();
    $todayConfirmed = (clone $baseToday)->where('status', 'confirmed')->count();
    $todayCompleted = (clone $baseToday)->where('status', 'completed')->count();

    // 💰 Use price (no total_price column in appointments)
    $todayRevenue = (clone $baseToday)
        ->whereIn('status', ['confirmed', 'completed'])
        ->sum('price');

    $recent = (clone $baseToday)
        ->with('service')
        ->orderBy('starts_at')
        ->limit(5)
        ->get()
        ->map(function (Appointment $a) {
            return [
                'customer_name' => $a->customer_name ?? $a->user?->name,
                'service_name'  => $a->service?->name,
                'starts_at'     => $a->starts_at instanceof Carbon
                    ? $a->starts_at->format('H:i')
                    : (string) $a->starts_at,
            ];
        });

    return response()->json([
        'today_total'     => (int) $todayTotal,
        'today_pending'   => (int) $todayPending,
        'today_confirmed' => (int) $todayConfirmed,
        'today_completed' => (int) $todayCompleted,
        'today_revenue'   => (float) $todayRevenue,
        'recent'          => $recent,
    ]);
}


    /**
     * GET /api/v1/admin/popular-services
     * Returns top booked services for charts.
     */
    public function popularServices(Request $request)
    {
        $limit = (int) $request->query('limit', 10);

        $rows = Appointment::selectRaw('service_id, COUNT(*) as aggregate_count')
            ->whereNotNull('service_id')
            ->groupBy('service_id')
            ->orderByDesc('aggregate_count')
            ->with('service:id,name')
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($row) {
            return [
                'service_id'   => $row->service_id,
                'service_name' => $row->service?->name,
                'count'        => (int) $row->aggregate_count,
            ];
        });

        return response()->json($data);
    }
}
