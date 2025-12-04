<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Appointment;
use App\Models\ServicePackage;
use App\Models\AppointmentLog;
use App\Models\Staff;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Staff area — manage own appointments.
 * Routes are protected by auth:sanctum and role:staff.
 *
 * Routes (from routes/api.php):
 *  GET    /api/v1/staff/appointments
 *  GET    /api/v1/staff/appointments/agenda
 *  GET    /api/v1/staff/appointments/today
 *  GET    /api/v1/staff/appointments/{appointment}
 *  POST   /api/v1/staff/appointments
 *  PATCH  /api/v1/staff/appointments/{appointment}
 *  PATCH  /api/v1/staff/appointments/{appointment}/confirm
 *  PATCH  /api/v1/staff/appointments/{appointment}/complete
 *  PATCH  /api/v1/staff/appointments/{appointment}/cancel
 *  PATCH  /api/v1/staff/appointments/{appointment}/no-show
 *  PATCH  /api/v1/staff/appointments/{appointment}/reschedule
 */
class StaffAppointmentController extends Controller
{
    /**
     * GET /api/v1/staff/appointments
     * Filters: date=YYYY-MM-DD OR from=YYYY-MM-DD&to=YYYY-MM-DD, status, service_id
     * Default view: today.
     */
    public function index(Request $request)
    {
        $staff = $request->user()->staff; // assumes User->staff relation
        abort_if(!$staff, 403, 'Not a staff member');

        $q = Appointment::query()
            ->with(['service:id,name,slug,duration_minutes', 'client:id,name,email,phone'])
            ->where('staff_id', $staff->id);

        // date filters
        $date = $request->query('date');
        $from = $request->query('from');
        $to   = $request->query('to');

        if ($date) {
            $q->whereDate('date', Carbon::parse($date)->toDateString());
        } else {
            if ($from) {
                $q->whereDate('date', '>=', Carbon::parse($from)->toDateString());
            }
            if ($to) {
                $q->whereDate('date', '<=', Carbon::parse($to)->toDateString());
            }
            if (!$from && !$to) {
                // default to "today"
                $q->whereDate('date', Carbon::today()->toDateString());
            }
        }

        // status filter
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        // service filter
        if ($request->filled('service_id')) {
            $q->where('service_id', (int) $request->query('service_id'));
        }

        // sorting
        $q->orderBy('date')->orderBy('starts_at');

        $perPage   = max(1, min(100, (int) $request->query('per_page', 20)));
        $paginator = $q->paginate($perPage)->appends($request->query());

        $items = collect($paginator->items())->map(fn(Appointment $a) => $this->presentAppointment($a));

        return response()->json([
            'filters' => [
                'date'       => $date,
                'from'       => $from,
                'to'         => $to,
                'status'     => $request->query('status'),
                'service_id' => $request->query('service_id'),
            ],
            'data' => $items,
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/staff/appointments
     *
     * Staff books an appointment for a client or walk-in.
     *
     * Body example:
     * {
     *   "service_id": 1,
     *   "date": "2025-11-25",
     *   "starts_at": "10:30",
     *   "client_id": 2,                    // optional (existing client)
     *   "client_phone": "+38970111222",    // required_without:client_id
     *   "client_name": "John Doe",         // required_without:client_id
     *   "client_email": "john@example.com",// optional
     *   "status": "confirmed"|"pending",   // optional, default confirmed
     *   "notes": "First visit"
     * }
     */
    public function store(Request $request)
{
    // 👤 Who is making the action (receptionist / staff user)
    $actingUser  = $request->user();
    $actingStaff = $actingUser->staff;
    abort_if(!$actingStaff, 403, 'Not a staff member');

    $data = $request->validate([
        'service_id'   => ['required', 'integer', 'exists:services,id'],

        // 🔥 NEW: target staff for this booking
        'staff_id'     => ['required', 'integer', 'exists:staff,id'],

        'date'         => ['required', 'date_format:Y-m-d'],
        'starts_at'    => ['required', 'date_format:H:i'],
        'client_id'    => ['nullable', 'integer', 'exists:users,id'],
        'client_phone' => ['required_without:client_id', 'nullable', 'string', 'max:50'],
        'client_name'  => ['required_without:client_id', 'nullable', 'string', 'max:255'],
        'client_email' => ['nullable', 'email', 'max:255'],
        'status'       => ['sometimes', Rule::in(['pending', 'confirmed'])],
        'notes'        => ['nullable', 'string', 'max:2000'],
    ]);

    $service = Service::findOrFail($data['service_id']);
    $status  = $data['status'] ?? Appointment::STATUS_CONFIRMED;
    $date    = $data['date'];
    $starts  = $data['starts_at'];

    // 🔥 NEW: find the staff that will actually perform the service
    $targetStaff = Staff::query()
        ->where('is_active', true)
        ->whereKey($data['staff_id'])
        ->whereHas('services', fn($q) => $q->where('services.id', $service->id))
        ->first();

    if (!$targetStaff) {
        return response()->json([
            'message' => 'Selected staff cannot perform this service or is inactive.',
        ], 422);
    }

    // Duration
    $duration = (int) ($service->duration_minutes ?? 0);

    // 🔎 Find or create user by phone (same as before)
    $user = null;

    if (!empty($data['client_id'])) {
        $user = User::findOrFail($data['client_id']);
    } elseif (! empty($data['client_phone'])) {
        $phone = trim($data['client_phone']);

        $user = User::withTrashed()
            ->where('phone', $phone)
            ->first();

        if ($user && $user->trashed()) {
            $user->restore();
        }

        if (! $user) {
            $user = User::create([
                'name'     => $data['client_name'] ?? $phone,
                'email'    => $data['client_email'] ?? null,
                'phone'    => $phone,
                'password' => Hash::make(Str::random(32)),
            ]);

            if (method_exists($user, 'assignRole')) {
                $user->assignRole('client');
            }
        }
    }

    // (optional) Overlap guard here if you want:
    // Appointment::assertNoOverlap(
    //     staffId: $targetStaff->id,
    //     serviceId: $service->id,
    //     date: $date,
    //     startsAt: $starts,
    //     durationMinutes: $duration,
    // );

    $appointment = null;

    DB::transaction(function () use (
        &$appointment,
        $service,
        $targetStaff,    // 🔥 note this
        $actingStaff,    // who is creating
        $user,
        $data,
        $date,
        $starts,
        $duration,
        $status
    ) {
        $appointment = new Appointment();

        $appointment->service_id       = $service->id;
        $appointment->staff_id         = $targetStaff->id;   // 🔥 BOOKED INTO SELECTED STAFF
        $appointment->user_id          = $user?->id;
        $appointment->date             = $date;
        $appointment->starts_at        = $starts;
        $appointment->duration_minutes = $duration;
        $appointment->price            = $service->price ?? 0;
        $appointment->status           = $status;
        $appointment->notes            = $data['notes'] ?? null;
        $appointment->reference_code   = 'STF-'.Str::upper(Str::random(8));

        // keep customer_* for legacy / invoices
        if ($user) {
            $appointment->customer_name  = $user->name;
            $appointment->customer_phone = $user->phone;
            $appointment->customer_email = $user->email;
        } else {
            $appointment->customer_name  = $data['client_name'] ?? null;
            $appointment->customer_phone = $data['client_phone'] ?? null;
            $appointment->customer_email = $data['client_email'] ?? null;
        }

        $appointment->save();

        AppointmentLog::create([
            'appointment_id' => $appointment->id,
            'action'         => 'created_by_staff',
            'meta'           => json_encode([
                'booked_by_staff_id'   => $actingStaff->id,
                'booked_by_staff_name' => $actingStaff->name,
                'assigned_staff_id'    => $targetStaff->id,
                'assigned_staff_name'  => $targetStaff->name,
            ]),
        ]);
    });

    $appointment->loadMissing(['service', 'client', 'staff', 'package']);

    return response()->json([
        'data' => $this->presentAppointment($appointment),
    ], 201);
}


    /**
     * GET /api/v1/staff/appointments/agenda
     * 7-day (or custom range) agenda, grouped by date.
     * Query: from=YYYY-MM-DD, to=YYYY-MM-DD (optional)
     * Default: from=today, to=today+6
     */
    public function agenda(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : Carbon::today();
        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : (clone $from)->addDays(6)->endOfDay();

        $appointments = Appointment::query()
            ->with(['service:id,name,slug,duration_minutes', 'client:id,name,email,phone'])
            ->where('staff_id', $staff->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('starts_at')
            ->get();

        $grouped = $appointments
            ->groupBy(function (Appointment $a) {
                return $a->date instanceof Carbon
                    ? $a->date->toDateString()
                    : Carbon::parse($a->date)->toDateString();
            })
            ->map(function ($group, $date) {
                return [
                    'date'         => $date,
                    'appointments' => $group->map(fn(Appointment $a) => $this->presentAppointment($a))->values(),
                ];
            })
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
            'data' => $grouped,
        ]);
    }

    /**
     * GET /api/v1/staff/appointments/today
     * Shortcut for today's appointments + summary.
     */
    public function today(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $today = Carbon::today()->toDateString();

        $appointments = Appointment::query()
            ->with(['service:id,name,slug,duration_minutes', 'client:id,name,email,phone'])
            ->where('staff_id', $staff->id)
            ->whereDate('date', $today)
            ->orderBy('starts_at')
            ->get();

        $summary = [
            'date'      => $today,
            'total'     => $appointments->count(),
            'pending'   => $appointments->where('status', 'pending')->count(),
            'confirmed' => $appointments->where('status', 'confirmed')->count(),
            'completed' => $appointments->where('status', 'completed')->count(),
            'cancelled' => $appointments->where('status', 'cancelled')->count(),
            'no_show'   => $appointments->where('status', 'no_show')->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'data'    => $appointments->map(fn(Appointment $a) => $this->presentAppointment($a))->values(),
        ]);
    }

    /**
     * GET /api/v1/staff/appointments/{id}
     */
   public function show(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with([
                'service.category',
                'client',
                'staff',
                'package',
            ])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        return response()->json([
            'data' => $this->presentAppointment($a),
        ]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}
     * Body: { status?, notes? }
     */
    public function update(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        $data = $request->validate([
            'status' => [
                'sometimes',
                Rule::in(['pending','confirmed','completed','cancelled','no_show']),
            ],
            'notes'  => ['sometimes','nullable','string','max:2000'],
        ]);

        $this->applyStaffUpdate($a, $data);

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/confirm
     * Body: { notes? }
     */
    public function confirm(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        $data = $request->validate([
            'notes' => ['sometimes','nullable','string','max:2000'],
        ]);
        $data['status'] = 'confirmed';

        $this->applyStaffUpdate($a, $data);

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/complete
     * Body: { notes? }
     */
    public function complete(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        $data = $request->validate([
            'notes' => ['sometimes','nullable','string','max:2000'],
        ]);
        $data['status'] = 'completed';

        $this->applyStaffUpdate($a, $data);

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/cancel
     * Body: { notes? }
     */
    public function cancel(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        $data = $request->validate([
            'notes' => ['sometimes','nullable','string','max:2000'],
        ]);
        $data['status'] = 'cancelled';

        $this->applyStaffUpdate($a, $data);

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/no-show
     * Body: { notes? }
     */
    public function noShow(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        $data = $request->validate([
            'notes' => ['sometimes','nullable','string','max:2000'],
        ]);
        $data['status'] = 'no_show';

        $this->applyStaffUpdate($a, $data);

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/reschedule
     * Body: { date: Y-m-d, starts_at: H:i }
     */
    public function reschedule(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['service','client','staff','package'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        if (in_array($a->status, ['completed','cancelled','no_show'], true)) {
            return response()->json(['message' => 'Cannot reschedule completed/cancelled/no-show appointments'], 422);
        }

        $v = $request->validate([
            'date'      => ['required','date_format:Y-m-d'],
            'starts_at' => ['required','date_format:H:i'],
        ]);

        $date      = $v['date'];
        $starts_at = $v['starts_at'];

        // 🔒 Overlap guard — adjust this call to match your actual helper signature.
        // Appointment::assertNoOverlap(...);

        DB::transaction(function () use ($a, $date, $starts_at) {
            $old = [
                'date'      => $a->date,
                'starts_at' => $a->starts_at,
            ];

            $a->date      = $date;
            $a->starts_at = $starts_at;

            // we do NOT store ends_at, only recalc for logs/display
            $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$starts_at);
            $endTime = null;
            if ($a->duration_minutes) {
                $endTime = $start->copy()
                    ->addMinutes((int) $a->duration_minutes)
                    ->format('H:i:s');
            }

            $a->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'rescheduled_by_staff',
                'meta'           => json_encode([
                    'from' => $old,
                    'to'   => [
                        'date'      => $a->date,
                        'starts_at' => $a->starts_at,
                        'ends_at'   => $endTime,
                    ],
                ]),
            ]);
        });

        $a->refresh()->loadMissing(['service','client','staff','package']);

        return response()->json(['data' => $this->presentAppointment($a)]);
    }

    // ----------------- Internal helpers ----------------- //

    /**
     * Shared logic for staff updates (status + notes + package deduction + logs).
     */
    private function applyStaffUpdate(Appointment $a, array $data): void
    {
        $originalStatus = $a->status;

        if (array_key_exists('status', $data)) {
            $next = $data['status'];

            $allowed = match ($originalStatus) {
                'pending'   => ['pending','confirmed','cancelled','no_show'],
                'confirmed' => ['confirmed','completed','cancelled','no_show'],
                'completed' => ['completed'],   // immutable
                'cancelled' => ['cancelled'],   // immutable
                'no_show'   => ['no_show'],     // immutable
                default     => [],
            };

            if (!in_array($next, $allowed, true)) {
                abort(422, 'Invalid status transition');
            }
        }

        DB::transaction(function () use ($a, $data, $originalStatus) {
            if (array_key_exists('notes', $data)) {
                $a->notes = $data['notes'];
            }
            if (array_key_exists('status', $data)) {
                $a->status = $data['status'];
            }
            $a->save();

            // log status change
            if ($a->wasChanged('status')) {
                AppointmentLog::create([
                    'appointment_id' => $a->id,
                    'action'         => 'status_changed',
                    'meta'           => json_encode([
                        'from' => $originalStatus,
                        'to'   => $a->status,
                    ]),
                ]);
            }

            // log notes update
            if ($a->wasChanged('notes')) {
                AppointmentLog::create([
                    'appointment_id' => $a->id,
                    'action'         => 'notes_updated',
                    'meta'           => json_encode(['notes' => $a->notes]),
                ]);
            }

            // package deduction only on transition TO completed (once)
            if ($originalStatus !== 'completed' && $a->status === 'completed') {
                $this->deductPackageIfNeeded($a);
            }
        });
    }

   private function presentAppointment(Appointment $a): array
    {
        // Normalize date
        $date = $a->date instanceof Carbon
            ? $a->date->toDateString()
            : Carbon::parse($a->date)->toDateString();

        // Safe start/end time (works for "10:00" and "10:00:00")
        $start = null;
        $end   = null;

        try {
            $start = Carbon::parse($date.' '.$a->starts_at);
            $duration = (int) $a->duration_minutes;

            if ($duration > 0) {
                $end = (clone $start)->addMinutes($duration);
            }
        } catch (\Throwable $e) {
            // ignore, $end stays null
        }

        // -------- 💰 Price calculations (clean model) --------
        $appointmentPrice = (float) ($a->price ?? 0.0);

        $package  = $a->package;
        $pkgTotal = $package?->price_total;
        $pkgPaid  = $package?->amount_paid ?? 0.0;

        if ($package && $pkgTotal !== null) {
            $totalPrice     = (float) $pkgTotal;
            $remainingPrice = max(0, (float)$pkgTotal - (float)$pkgPaid);
        } else {
            $totalPrice     = $appointmentPrice;
            $remainingPrice = null;
        }

        return [
            'id'       => $a->id,
            'status'   => $a->status,

            // 💰 For staff UI header
            'price'           => $appointmentPrice,
            'total_price'     => $totalPrice,
            'remaining_price' => $remainingPrice,

            'service'  => [
                'id'    => $a->service?->id,
                'name'  => $a->service?->name,
                'slug'  => $a->service?->slug,
                // what this appointment is billed at
                'price' => (float) $a->price,
            ],

            'client'   => $a->client ? [
                'id'    => $a->client->id,
                'name'  => $a->client->name,
                'email' => $a->client->email,
                'phone' => $a->client->phone,
            ] : [
                'name'  => $a->customer_name,
                'email' => $a->customer_email,
                'phone' => $a->customer_phone,
            ],

            'date'      => $date,
            'starts_at' => $a->starts_at,
            'ends_at'   => $end?->format('H:i:s'),
            'duration'  => (int) $a->duration_minutes,

            'package'   => $package ? [
                'id'                 => $package->id,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes'  => $package->remaining_minutes,
                'status'             => $package->status,

                // 🔥 clean money model here
                'price_total'        => (float) $pkgTotal,
                'amount_paid'        => (float) $pkgPaid,
                'remaining_balance'  => $remainingPrice,
            ] : null,

            'notes' => $a->notes,
        ];
    }


    /**
     * Deduct from linked package if eligible. Mirrors Admin logic in a safe way.
     */
    private function deductPackageIfNeeded(Appointment $a): void
    {
        if (!$a->service_package_id) {
            return;
        }

        // has a previous 'package_deducted' log?
        $already = AppointmentLog::where('appointment_id', $a->id)
            ->where('action', 'package_deducted')
            ->exists();
        if ($already) {
            return;
        }

        $pkg = ServicePackage::lockForUpdate()->find($a->service_package_id);
        if (!$pkg || $pkg->status !== 'active') {
            return;
        }

        $changed = false;

        if (!is_null($pkg->remaining_sessions)) {
            if ($pkg->remaining_sessions > 0) {
                $pkg->remaining_sessions -= 1;
                $changed = true;
            }
        } elseif (!is_null($pkg->remaining_minutes)) {
            $mins = (int) $a->duration_minutes;
            if ($mins > 0 && $pkg->remaining_minutes > 0) {
                $pkg->remaining_minutes = max(0, $pkg->remaining_minutes - $mins);
                $changed = true;
            }
        }

        if ($changed) {
            // auto-mark used if depleted
            if (
                (!is_null($pkg->remaining_sessions) && $pkg->remaining_sessions <= 0) ||
                (!is_null($pkg->remaining_minutes)  && $pkg->remaining_minutes <= 0)
            ) {
                $pkg->status = 'used';
            }
            $pkg->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'package_deducted',
                'meta'           => json_encode([
                    'package_id'         => $pkg->id,
                    'remaining_sessions' => $pkg->remaining_sessions,
                    'remaining_minutes'  => $pkg->remaining_minutes,
                ]),
            ]);
        }
    }
}
