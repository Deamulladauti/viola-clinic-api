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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AppointmentAdminController extends Controller
{
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

    // 5) Admin create
    public function store(AppointmentStoreRequest $request)
    {
        $data = $request->validated();

        // Copy duration/price from Service when not provided
        if (!isset($data['duration_minutes']) || !isset($data['price'])) {
            $service = Service::findOrFail($data['service_id']);
            $data['duration_minutes'] = $data['duration_minutes'] ?? (int) ($service->duration_minutes ?? 60);
            $data['price']            = $data['price'] ?? (float) ($service->price ?? 0);
        }

        // Overlap guard (same service OR same staff if staff_id is set)
        $this->assertNoOverlap(
            $data['date'],
            $data['starts_at'],
            (int) $data['duration_minutes'],
            (int) $data['service_id'],
            $data['staff_id'] ?? null,
            null // ignoreId
        );

        // Ensure unique reference code
        $data['reference_code'] = $this->newReferenceCode();

        $appt = Appointment::create($data)->load('service');

        return response()->json([
            'message'     => 'Appointment created',
            'appointment' => $appt,
        ], 201);
    }

    // 6) Admin update (status/notes + transition rules)
    public function update(AppointmentUpdateRequest $request, Appointment $appointment)
    {
        $data = $request->validated();

        if (isset($data['status'])) {
            $this->assertTransitionAllowed($appointment->status, $data['status']);
        }

        // Only notes and status are allowed here (as per minimal spec)
        $updates = [];
        if (array_key_exists('status', $data)) $updates['status'] = $data['status'];
        if (array_key_exists('notes',  $data)) $updates['notes']  = $data['notes'];

        if (!empty($updates)) {
            $appointment->fill($updates)->save();
        }

        // Side-effects when completing
        if (($updates['status'] ?? null) === 'completed') {
            $appointment->refresh()->load('service');
            $this->handleCompletionSideEffects($appointment);
        }

        return response()->json([
            'message'     => 'Appointment updated',
            'appointment' => $appointment->fresh()->load('service'),
        ]);
    }

    // 7) Admin delete (soft delete)
    public function destroy(Appointment $appointment)
    {
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

     public function showBooking(Request $request, Appointment $appointment)
{
    $appointment->loadMissing([
        'service.category',
        'staff',
        'user',
        'package.logs',   // 🔥 load usage logs for the package
        'logs.user',
    ]);

    $service  = $appointment->service;
    $category = $service?->category;
    $staff    = $appointment->staff;
    $user     = $appointment->user;      // alias for client()
    $package  = $appointment->package;   // ServicePackage via service_package_id

    // ----------------------
    // 💰 Price calculations
    // ----------------------
    $appointmentPrice = (float) ($appointment->price ?? 0.0);

    // Prefer new schema: price_total + amount_paid
    $packagePriceTotal = $package?->price_total; // may be null
    $packagePricePaid  = $package?->amount_paid ?? $package?->price_paid;

    // total_price for this booking:
    // - if package exists & has total -> use package total
    // - otherwise fall back to appointment price
    if ($package && $packagePriceTotal !== null) {
        $totalPrice = (float) $packagePriceTotal;
    } else {
        $totalPrice = $appointmentPrice;
    }

    // remaining_price from package if possible
    $remainingPrice = null;
    if ($package && $packagePriceTotal !== null && $packagePricePaid !== null) {
        $remainingPrice = max(
            0,
            (float)$packagePriceTotal - (float)$packagePricePaid
        );
    }

    return response()->json([
        'id'             => $appointment->id,
        'reference_code' => $appointment->reference_code,
        'status'         => $appointment->status,

        'date'      => $appointment->date instanceof Carbon
            ? $appointment->date->toDateString()
            : (string) $appointment->date,
        'starts_at' => (string) $appointment->starts_at,
        'duration_minutes' => $appointment->duration_minutes,

        // 💰 Prices on this booking
        'price'           => $appointmentPrice,  // single-session price
        'total_price'     => $totalPrice,        // package total or appointment price
        'remaining_price' => $remainingPrice,    // null if no package / no info

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
            'duration_minutes' => $service->duration_minutes,
            'base_price'       => $service->price,
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
            'id'                 => $package->id,
            'service_id'         => $package->service_id,
            'service_name'       => $package->service_name,
            'status'             => $package->status,

            'price_total'        => $package->price_total !== null
                ? (float) $package->price_total
                : null,
            'price_paid'         => $package->price_paid !== null
                ? (float) $package->price_paid
                : null,
            'amount_paid'        => $package->amount_paid !== null
                ? (float) $package->amount_paid
                : null,

            // 💰 balance at package level (same as remaining_price, but scoped to package)
            'remaining_balance'  => $remainingPrice,

            'remaining_sessions' => $package->remaining_sessions,
            'remaining_minutes'  => $package->remaining_minutes,
            'starts_on'          => optional($package->starts_on)->toDateString(),
            'expires_on'         => optional($package->expires_on)->toDateString(),

            // 🧾 usage history: when sessions/minutes were spent
            'usage_logs'         => $package->logs->map(function ($log) {
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


    private function handleCompletionSideEffects(Appointment $appointment): void
    {
        $appointment->loadMissing('service');

        $service = $appointment->service;
        $userId  = $appointment->user_id;

        if (!$service || !$userId) return;

        // Prefer explicitly linked package (avoids deducting the wrong one if multiple exist)
        $pkg = null;
        if ($appointment->service_package_id) {
            $pkg = ServicePackage::find($appointment->service_package_id);
        }

        // Fallback: find an active package for this user+service (date-valid)
        if (!$pkg) {
            $today = Carbon::today();
            $pkg = ServicePackage::query()
                ->where('user_id', $userId)
                ->where('service_id', $service->id)
                ->where('status', 'active')
                ->where(function ($q) use ($today) {
                    $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today);
                })
                ->first();
        }

        if (!$pkg) {
            \Log::info('No active package found for completion of appt #'.$appointment->id);
            return;
        }

        try {
            // -------- Sessions-type (e.g., Laser 6 sessions) --------
            if (!is_null($pkg->remaining_sessions) && (int)$pkg->remaining_sessions > 0) {
                if (!empty($service->is_package) && !empty($service->total_sessions)) {
                    // deduct 1 session per completed appointment
                    if (method_exists($pkg, 'deductSessions')) {
                        $pkg->deductSessions(
                            1,
                            staffId: $appointment->staff_id,
                            note: 'Auto-deduct session on completion (appt #'.$appointment->id.')'
                        );
                    } else {
                        // minimal fallback
                        $pkg->decrement('remaining_sessions', 1);
                    }
                }
            }

            // -------- Minutes-type (e.g., Solarium minutes) --------
            // If the package tracks minutes, deduct the appointment duration (or service duration).
            if (!is_null($pkg->remaining_minutes)) {
                $minutesToDeduct = (int) ($appointment->duration_minutes ?? $service->duration_minutes ?? 0);
                if ($minutesToDeduct > 0) {
                    if (method_exists($pkg, 'deductMinutes')) {
                        $pkg->deductMinutes(
                            $minutesToDeduct,
                            staffId: $appointment->staff_id,
                            note: 'Auto-deduct minutes on completion (appt #'.$appointment->id.')'
                        );
                    } else {
                        // minimal fallback with floor at 0
                        $new = max(0, (int)$pkg->remaining_minutes - $minutesToDeduct);
                        $pkg->update(['remaining_minutes' => $new]);
                    }
                }
            }

        } catch (\Throwable $e) {
            \Log::warning('Completion side-effects failed for appt #'.$appointment->id.': '.$e->getMessage());
        }
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
    public function updateStatus(AdminUpdateAppointmentStatusRequest $request, Appointment $appointment)
    {
        $to   = $request->validated()['status'];
        $from = $appointment->status;

        if ($from === $to) {
            return response()->json([
                'message' => 'Status unchanged.',
                'data'    => ['id' => $appointment->id, 'status' => $appointment->status],
            ]);
        }

        // If moving to CONFIRMED, ensure no overlap first
        if ($to === 'confirmed') {
            $service  = Service::findOrFail($appointment->service_id);
            $dateOnly = Carbon::parse($appointment->date)->toDateString();
            $startsAt = $appointment->starts_at ?? $appointment->time;
            $startsAt = strlen($startsAt) === 5 ? $startsAt . ':00' : $startsAt;

            $this->assertNoOverlap(
                $dateOnly,
                $startsAt,
                (int) $service->duration_minutes,
                (int) $service->id,
                $appointment->staff_id,
                (int) $appointment->id
            );
        }

        DB::transaction(function () use ($request, $appointment, $from, $to) {
            // Optional timestamp columns if present
            // $stampFields = [
            //     'confirmed' => 'confirmed_at',
            //     'cancelled' => 'cancelled_at',
            //     'completed' => 'completed_at',
            //     'no_show'   => 'no_show_at',
            // ];
            // if (isset($stampFields[$to]) && Schema::hasColumn('appointments', $stampFields[$to])) {
            //     $appointment->{$stampFields[$to]} = now();
            // }

            // Update status + optional notes
            $appointment->status = $to;
            if ($request->filled('notes') && Schema::hasColumn('appointments', 'notes')) {
                $appointment->notes = $request->input('notes');
            }
            $appointment->save();

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id'        => optional(request()->user())->id,
                'action'         => 'status_changed',
                'meta'           => ['from' => $from, 'to' => $to],
            ]);

            // Side-effects if completed
            if ($to === 'completed') {
                $appointment->refresh()->load('service');
                $this->handleCompletionSideEffects($appointment);
            }
        });

        return response()->json([
            'message' => "Appointment status updated to {$to}.",
            'data'    => [
                'id'        => $appointment->id,
                'status'    => $appointment->status,
                'date'      => $appointment->date,
                'starts_at' => $appointment->starts_at,
                'staff_id'  => $appointment->staff_id,
                'service_id'=> $appointment->service_id,
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
