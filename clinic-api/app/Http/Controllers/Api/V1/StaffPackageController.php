<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\ServicePackage;
use App\Models\PackageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Service;

/**
 * Staff area — package utilities while working with clients.
 */
class StaffPackageController extends Controller
{
    /**
     * GET /api/v1/staff/packages
     * Filters: client_id (required), service_id? (optional), status? (active|used|expired|frozen)
     */
    public function index(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'client_id'  => ['required', 'integer', 'min:1'],
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'status'     => ['sometimes', Rule::in(['active', 'used', 'expired', 'frozen'])],
        ]);

        $q = ServicePackage::query()
            ->where('user_id', $data['client_id']);

        if (!empty($data['service_id'])) {
            $q->where('service_id', (int) $data['service_id']);
        }

        if (!empty($data['status'])) {
            $q->where('status', $data['status']);
        }

        $packages = $q->orderBy('status')->orderBy('expires_on')->get([
            'id',
            'user_id',
            'service_id',
            'service_name',
            'price_total',
            'price_paid',
            'currency',
            'remaining_sessions',
            'remaining_minutes',
            'starts_on',
            'expires_on',
            'status',
        ]);

        return response()->json([
            'data' => $packages->map(function (ServicePackage $p) {
                return [
                    'id'                    => $p->id,
                    'service_id'            => $p->service_id,
                    'service_name'          => $p->service_name,
                    'remaining_sessions'    => $p->remaining_sessions,
                    'remaining_minutes'     => $p->remaining_minutes,
                    'price_total'           => (float) ($p->price_total ?? 0),
                    'amount_paid'           => (float) ($p->amount_paid ?? 0),
                    'remaining_balance'     => (float) ($p->remaining_to_pay ?? 0),
                    'amount_paid_mkd'       => (float) ($p->amount_paid_mkd ?? 0),
                    'remaining_balance_mkd' => (float) ($p->remaining_to_pay_mkd ?? 0),
                    'currency'              => $p->currency,
                    'starts_on'             => $p->starts_on?->toDateString(),
                    'expires_on'            => $p->expires_on?->toDateString(),
                    'status'                => $p->status,
                ];
            }),
        ]);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/attach-package
     * Body: { package_id }
     */
    public function attachToAppointment(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::with(['client', 'service'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        if (!$a->client) {
            return response()->json(['message' => 'Only appointments with registered clients can attach a package'], 422);
        }

        $v = $request->validate([
            'package_id' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($a, $v) {
            $pkg = ServicePackage::lockForUpdate()->find($v['package_id']);

            if (!$pkg || $pkg->user_id !== $a->client->id) {
                abort(422, 'Package does not belong to this client.');
            }

            if ($pkg->service_id !== $a->service_id) {
                abort(422, 'Package is for a different service.');
            }

            if ($pkg->status !== 'active') {
                abort(422, 'Package is not active.');
            }

            if ($pkg->starts_on && Carbon::today()->lt(Carbon::parse($pkg->starts_on))) {
                abort(422, 'Package not started yet.');
            }

            if ($pkg->expires_on && Carbon::today()->gt(Carbon::parse($pkg->expires_on))) {
                abort(422, 'Package expired.');
            }

            $ok = false;

            if (!is_null($pkg->remaining_sessions) && $pkg->remaining_sessions > 0) {
                $ok = true;
            }

            if (!is_null($pkg->remaining_minutes) && $pkg->remaining_minutes >= (int) $a->duration_minutes) {
                $ok = true;
            }

            if (!$ok) {
                abort(422, 'Package has insufficient balance.');
            }

            $a->service_package_id = $pkg->id;
            $a->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'package_attached',
                'meta'           => json_encode(['package_id' => $pkg->id]),
            ]);
        });

        $a->refresh()->loadMissing('package');

        return response()->json([
            'message' => 'Package attached',
            'data'    => [
                'appointment_id' => $a->id,
                'package_id'     => $a->service_package_id,
            ],
        ], 200);
    }

    /**
     * PATCH /api/v1/staff/appointments/{id}/detach-package
     */
    public function detachFromAppointment(Request $request, int $id)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $a = Appointment::where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        if ($a->status === 'completed') {
            return response()->json(['message' => 'Cannot detach package from a completed appointment'], 422);
        }

        if ($a->service_package_id) {
            $pkgId = $a->service_package_id;
            $a->service_package_id = null;
            $a->save();

            AppointmentLog::create([
                'appointment_id' => $a->id,
                'action'         => 'package_detached',
                'meta'           => json_encode(['package_id' => $pkgId]),
            ]);
        }

        return response()->json([
            'message' => 'Package detached',
            'data'    => ['appointment_id' => $a->id],
        ]);
    }

    /**
     * POST /api/v1/staff/packages/{package}/use
     */
    public function usePackage(Request $request, ServicePackage $package)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'type'           => ['required', 'in:session,minutes'],
            'amount'         => ['required', 'integer', 'min:1'],
            'appointment_id' => ['sometimes', 'nullable', 'integer', 'exists:appointments,id'],
            'note'           => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $warning = null;

        DB::transaction(function () use ($package, $data, $staff, &$warning) {
            if ($package->status !== 'active') {
                abort(422, 'Package is not active.');
            }

            if ($package->starts_on && Carbon::today()->lt(Carbon::parse($package->starts_on))) {
                abort(422, 'Package not started yet.');
            }

            if ($package->expires_on && Carbon::today()->gt(Carbon::parse($package->expires_on))) {
                abort(422, 'Package expired.');
            }

            $requested = (int) $data['amount'];
            $usedSessions = null;
            $usedMinutes = null;

            if ($data['type'] === 'session') {
                if (is_null($package->remaining_sessions)) {
                    abort(422, 'This package does not track sessions.');
                }

                $before = (int) $package->remaining_sessions;
                $deduct = min($before, $requested);
                $over = max(0, $requested - $before);

                $package->remaining_sessions = $before - $deduct;
                $usedSessions = $deduct;

                if ($over > 0) {
                    $warning = "Requested {$requested} sessions, but only {$before} were remaining. Deducted {$deduct}.";
                }
            } else {
                if (is_null($package->remaining_minutes)) {
                    abort(422, 'This package does not track minutes.');
                }

                $before = (int) $package->remaining_minutes;
                $deduct = min($before, $requested);
                $over = max(0, $requested - $before);

                $package->remaining_minutes = $before - $deduct;
                $usedMinutes = $deduct;

                if ($over > 0) {
                    $warning = "Requested {$requested} minutes, but only {$before} were remaining. Deducted {$deduct}.";
                }
            }

            if (
                (!is_null($package->remaining_sessions) && (int) $package->remaining_sessions <= 0) ||
                (!is_null($package->remaining_minutes) && (int) $package->remaining_minutes <= 0)
            ) {
                $package->status = 'used';
            }

            $package->save();

            $appointmentId = $data['appointment_id'] ?? null;
            $appointmentRef = null;

            if ($appointmentId) {
                $appointment = Appointment::find($appointmentId);
                $appointmentRef = $appointment?->reference_code;
            }

            PackageLog::create([
                'service_package_id' => $package->id,
                'staff_id'           => $staff->id,
                'appointment_id'     => $appointmentId,
                'appointment_ref'    => $appointmentRef,
                'used_sessions'      => $usedSessions,
                'used_minutes'       => $usedMinutes,
                'used_at'            => now(),
                'note'               => $data['note'] ?? null,
            ]);
        });

        $package->refresh();

        return response()->json([
            'message' => 'Package usage recorded',
            'warning' => $warning,
            'data'    => [
                'id'                 => $package->id,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes'  => $package->remaining_minutes,
                'status'             => $package->status,
            ],
        ]);
    }

    /**
     * POST /api/v1/staff/packages/{package}/payments
     *
     * Payment rules:
     * - card: always MKD, no currency needed from frontend
     * - cash: currency required, only EUR or MKD
     * - EUR cash: converted with ServicePackage::EUR_TO_MKD
     */
    public function addPayment(Request $request, ServicePackage $package)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'card'])],
            'currency' => [
                'nullable',
                'string',
                'size:3',
                Rule::requiredIf(fn () => $request->input('method') === 'cash'),
                Rule::in(['EUR', 'MKD']),
            ],
            'note'           => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $normalized = $this->normalizePaymentData($data);

        $priceTotalMkd = (float) $package->priceTotalMkd();
        $remainingMkd = (float) $package->remaining_to_pay_mkd;

        if ($priceTotalMkd <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Package has no total price set.',
            ], 422);
        }

        if ($normalized['amount_mkd'] > $remainingMkd + 0.01) {
            return response()->json([
                'ok'                   => false,
                'message'              => 'Amount exceeds remaining balance.',
                'remaining_before'     => (float) $package->remaining_to_pay,
                'remaining_before_mkd' => $remainingMkd,
                'package_currency'     => $package->packageCurrency(),
            ], 422);
        }

        $payment = DB::transaction(function () use ($package, $data, $normalized, $staff) {
            return $package->payments()->create([
                'service_package_id' => $package->id,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'user_id'            => $package->user_id,
                'staff_id'           => $staff->id,
                'admin_id'           => null,
                'method'             => $normalized['method'],
                'amount'             => round((float) $data['amount'], 2),
                'currency'           => $normalized['currency'],
                'exchange_rate'      => $normalized['exchange_rate'],
                'amount_mkd'         => $normalized['amount_mkd'],
                'notes'              => $data['note'] ?? null,
            ]);
        });

        $package->refresh();

        return response()->json([
            'ok'                     => true,
            'message'                => 'Payment recorded (staff).',
            'payment'                => [
                'id'             => $payment->id,
                'amount'         => (float) $payment->amount,
                'currency'       => $payment->currency,
                'method'         => $payment->method,
                'exchange_rate'  => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                'amount_mkd'     => $payment->amount_mkd !== null ? (float) $payment->amount_mkd : null,
                'notes'          => $payment->notes,
                'created_at'     => optional($payment->created_at)?->toDateTimeString(),
            ],
            'package_id'             => $package->id,
            'price_total'            => (float) ($package->price_total ?? 0),
            'amount_paid'            => (float) $package->amount_paid,
            'remaining_balance'      => (float) $package->remaining_to_pay,
            'amount_paid_mkd'        => (float) $package->amount_paid_mkd,
            'remaining_balance_mkd'  => (float) $package->remaining_to_pay_mkd,
            'currency'               => $package->currency,
        ]);
    }

    /**
     * Staff creates a solarium package for a client.
     */
    public function store(Request $request)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'client_id'  => ['required', 'integer', 'exists:users,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'notes'      => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $client = User::where('id', $data['client_id'])
            ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->first();

        if (!$client) {
            return response()->json(['message' => 'User is not a client'], 422);
        }

        $service = Service::find($data['service_id']);

        if (!$service || !$service->is_active) {
            return response()->json(['message' => 'Service not found or inactive'], 422);
        }

        if ($service->category?->name !== 'Solarium') {
            return response()->json(['message' => 'This endpoint is for Solarium packages only'], 422);
        }

        preg_match('/(\d+)\s*Minutes/i', $service->name, $m);

        if (empty($m[1])) {
            return response()->json(['message' => 'Could not determine minutes from service name'], 422);
        }

        $minutes = (int) $m[1];
        $price = (float) $service->price;

        $package = null;

        DB::transaction(function () use (&$package, $client, $service, $minutes, $price, $staff, $data) {
            $package = ServicePackage::create([
                'user_id'                => $client->id,
                'service_id'             => $service->id,
                'service_name'           => $service->name,
                'snapshot_total_minutes' => $minutes,
                'remaining_minutes'      => $minutes,
                'price_total'            => $price,
                'price_paid'             => $price,
                'currency'               => 'EUR',
                'status'                 => 'active',
                'starts_on'              => now()->toDateString(),
                'notes'                  => $data['notes'] ?? null,
            ]);

            PackageLog::create([
                'service_package_id' => $package->id,
                'staff_id'           => $staff->id,
                'used_sessions'      => 0,
                'used_minutes'       => 0,
                'used_at'            => now(),
                'note'               => 'Solarium package created by staff',
            ]);
        });

        return response()->json([
            'message' => 'Solarium package created',
            'data' => [
                'id'                    => $package->id,
                'service_name'          => $package->service_name,
                'remaining_minutes'     => $package->remaining_minutes,
                'price_total'           => (float) $package->price_total,
                'amount_paid'           => (float) $package->amount_paid,
                'remaining_balance'     => (float) $package->remaining_to_pay,
                'amount_paid_mkd'       => (float) $package->amount_paid_mkd,
                'remaining_balance_mkd' => (float) $package->remaining_to_pay_mkd,
                'currency'              => $package->currency,
                'status'                => $package->status,
            ],
        ], 201);
    }

    public function forClient(Request $request, int $client)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'status'     => ['sometimes', Rule::in(['active', 'used', 'expired', 'frozen'])],
        ]);

        $q = ServicePackage::query()
            ->where('user_id', $client);

        if (!empty($data['service_id'])) {
            $q->where('service_id', (int) $data['service_id']);
        }

        if (!empty($data['status'])) {
            $q->where('status', $data['status']);
        }

        $packages = $q->orderBy('status')->orderByDesc('created_at')->get([
            'id',
            'user_id',
            'service_id',
            'service_name',
            'snapshot_total_sessions',
            'snapshot_total_minutes',
            'remaining_sessions',
            'remaining_minutes',
            'price_total',
            'price_paid',
            'currency',
            'starts_on',
            'expires_on',
            'status',
            'notes',
            'created_at',
        ]);

        return response()->json([
            'data' => $packages->map(function (ServicePackage $p) {
                return [
                    'id'                      => $p->id,
                    'user_id'                 => $p->user_id,
                    'service_id'              => $p->service_id,
                    'service_name'            => $p->service_name,
                    'snapshot_total_sessions' => $p->snapshot_total_sessions,
                    'snapshot_total_minutes'  => $p->snapshot_total_minutes,
                    'remaining_sessions'      => $p->remaining_sessions,
                    'remaining_minutes'       => $p->remaining_minutes,
                    'price_total'             => (float) ($p->price_total ?? 0),
                    'amount_paid'             => (float) ($p->amount_paid ?? 0),
                    'remaining_balance'       => (float) ($p->remaining_to_pay ?? 0),
                    'amount_paid_mkd'         => (float) ($p->amount_paid_mkd ?? 0),
                    'remaining_balance_mkd'   => (float) ($p->remaining_to_pay_mkd ?? 0),
                    'currency'                => $p->currency,
                    'starts_on'               => $p->starts_on?->toDateString(),
                    'expires_on'              => $p->expires_on?->toDateString(),
                    'status'                  => $p->status,
                    'notes'                   => $p->notes,
                    'created_at'              => $p->created_at?->toISOString(),
                ];
            }),
        ]);
    }

    public function logs(Request $request, ServicePackage $package)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $logs = PackageLog::query()
            ->where('service_package_id', $package->id)
            ->orderByDesc('used_at')
            ->limit(100)
            ->get([
                'id',
                'service_package_id',
                'staff_id',
                'appointment_id',
                'appointment_ref',
                'used_sessions',
                'used_minutes',
                'used_at',
                'note',
            ]);

        return response()->json([
            'data' => $logs->map(fn ($l) => [
                'id'              => $l->id,
                'package_id'      => $l->service_package_id,
                'staff_id'        => $l->staff_id,
                'appointment_id'  => $l->appointment_id,
                'appointment_ref' => $l->appointment_ref,
                'used_sessions'   => $l->used_sessions,
                'used_minutes'    => $l->used_minutes,
                'used_at'         => optional($l->used_at)->toISOString() ?? (string) $l->used_at,
                'note'            => $l->note,
            ]),
        ]);
    }

    private function normalizePaymentData(array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        $method = strtolower((string) $data['method']);

        if ($method === 'card') {
            return [
                'method'        => 'card',
                'currency'      => 'MKD',
                'exchange_rate' => null,
                'amount_mkd'    => $amount,
            ];
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'MKD'));

        if ($currency === 'EUR') {
            return [
                'method'        => 'cash',
                'currency'      => 'EUR',
                'exchange_rate' => ServicePackage::EUR_TO_MKD,
                'amount_mkd'    => round($amount * ServicePackage::EUR_TO_MKD, 2),
            ];
        }

        return [
            'method'        => 'cash',
            'currency'      => 'MKD',
            'exchange_rate' => null,
            'amount_mkd'    => $amount,
        ];
    }
}
