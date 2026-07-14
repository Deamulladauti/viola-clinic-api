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
use App\Services\PackageUsageService;

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
            'status'     => ['sometimes', Rule::in(ServicePackage::statuses())],
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
            'assigned_staff_id',
            'snapshot_usage_type',
            'snapshot_minimum_interval_days',
            'snapshot_deduction_method',
            'snapshot_staff_policy',
            'snapshot_duration_minutes',
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
                    'usage_type'            => $p->snapshot_usage_type,
                    'minimum_interval_days'  => $p->snapshot_minimum_interval_days,
                    'staff_policy'           => $p->snapshot_staff_policy,
                    'assigned_staff_id'      => $p->assigned_staff_id,
                    'next_allowed_date'      => $p->next_allowed_date,
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
        abort_if(! $staff, 403, 'Not a staff member');

        $appointment = Appointment::with(['client', 'service'])
            ->where('id', $id)
            ->where('staff_id', $staff->id)
            ->first();

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found'], 404);
        }

        if (! $appointment->client) {
            return response()->json(['message' => 'Only registered-client appointments can use a package.'], 422);
        }

        if ($appointment->status === Appointment::STATUS_COMPLETED) {
            return response()->json(['message' => 'A package cannot be attached after completion.'], 422);
        }

        $data = $request->validate([
            'package_id' => ['required', 'integer', 'exists:service_packages,id'],
        ]);

        DB::transaction(function () use ($appointment, $data, $staff) {
            $package = ServicePackage::query()->lockForUpdate()->findOrFail($data['package_id']);

            if ((int) $package->user_id !== (int) $appointment->client->id) {
                abort(422, 'Package does not belong to this client.');
            }

            if ((int) $package->service_id !== (int) $appointment->service_id) {
                abort(422, 'Package is for a different service.');
            }

            try {
                $package->assertUsableOn($appointment->date);
            } catch (\LogicException $exception) {
                abort(422, $exception->getMessage());
            }

            if (! $package->isSessionsType()) {
                abort(422, 'Quantity/minute packages are walk-in only and cannot be attached to appointments.');
            }

            if ((int) $package->remaining_sessions <= 0) {
                abort(422, 'Package has no sessions remaining.');
            }

            if ($package->staffPolicy() === Service::STAFF_SAME) {
                $hasCompletedSession = $package->activeUsageLogs()
                    ->where('usage_type', Service::USAGE_SESSION)
                    ->exists();

                if ($hasCompletedSession && (int) $package->assigned_staff_id !== (int) $staff->id) {
                    abort(422, 'This package is locked to its assigned staff member.');
                }

                if (! $hasCompletedSession && (int) $package->assigned_staff_id !== (int) $staff->id) {
                    $package->assigned_staff_id = $staff->id;
                    $package->save();
                }
            }

            $appointment->service_package_id = $package->id;
            $appointment->save();

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => optional(request()->user())->id,
                'action' => 'package_attached',
                'meta' => ['package_id' => $package->id],
            ]);
        });

        $appointment->refresh()->loadMissing('package');

        return response()->json([
            'message' => 'Package attached',
            'data' => [
                'appointment_id' => $appointment->id,
                'package_id' => $appointment->service_package_id,
            ],
        ]);
    }

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
    public function usePackage(Request $request, ServicePackage $package, PackageUsageService $usageService)
    {
        $staff = $request->user()->staff;
        abort_if(! $staff, 403, 'Not a staff member');

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['minutes'])],
            'amount' => ['required', 'integer', 'min:1'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $log = $usageService->recordManualQuantityUsage(
            package: $package,
            quantity: (int) $data['amount'],
            occurredOn: $data['date'] ?? now()->toDateString(),
            staffId: $staff->id,
            actorUserId: $request->user()->id,
            note: $data['note'] ?? null,
            source: PackageLog::SOURCE_MANUAL,
        );

        $package->refresh();

        return response()->json([
            'message' => 'Quantity package usage recorded',
            'data' => [
                'id' => $package->id,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes' => $package->remaining_minutes,
                'status' => $package->status,
                'usage_id' => $log->id,
                'occurred_on' => optional($log->occurred_on)?->toDateString(),
            ],
        ]);
    }

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
        abort_if(! $staff, 403, 'Not a staff member');

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $client = User::where('id', $data['client_id'])
            ->whereHas('roles', fn ($query) => $query->where('name', 'client'))
            ->first();

        if (! $client) {
            return response()->json(['message' => 'User is not a client'], 422);
        }

        $service = Service::findOrFail($data['service_id']);

        if (! $service->is_active || ! $service->is_package || $service->usage_type !== Service::USAGE_MINUTES) {
            return response()->json(['message' => 'Selected service is not a configured quantity/minute package.'], 422);
        }

        $minutes = (int) $service->total_minutes;
        if ($minutes <= 0) {
            return response()->json(['message' => 'The package has no included minutes configured.'], 422);
        }

        $package = ServicePackage::create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_minutes' => $minutes,
            'snapshot_usage_type' => $service->usage_type,
            'snapshot_minimum_interval_days' => (int) ($service->minimum_interval_days ?? 0),
            'snapshot_deduction_method' => $service->deduction_method,
            'snapshot_staff_policy' => $service->staff_policy,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'remaining_minutes' => $minutes,
            'price_total' => (float) $service->price,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Quantity package created',
            'data' => [
                'id' => $package->id,
                'service_name' => $package->service_name,
                'usage_type' => $package->snapshot_usage_type,
                'remaining_minutes' => $package->remaining_minutes,
                'price_total' => (float) $package->price_total,
                'amount_paid' => (float) $package->amount_paid,
                'remaining_balance' => (float) $package->remaining_to_pay,
                'amount_paid_mkd' => (float) $package->amount_paid_mkd,
                'remaining_balance_mkd' => (float) $package->remaining_to_pay_mkd,
                'currency' => $package->currency,
                'status' => $package->status,
            ],
        ], 201);
    }

    public function forClient(Request $request, int $client)
    {
        $staff = $request->user()->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'status'     => ['sometimes', Rule::in(ServicePackage::statuses())],
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
            'snapshot_usage_type',
            'snapshot_minimum_interval_days',
            'snapshot_deduction_method',
            'snapshot_staff_policy',
            'snapshot_duration_minutes',
            'assigned_staff_id',
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
                    'usage_type'                => $p->snapshot_usage_type,
                    'minimum_interval_days'      => $p->snapshot_minimum_interval_days,
                    'deduction_method'           => $p->snapshot_deduction_method,
                    'staff_policy'               => $p->snapshot_staff_policy,
                    'assigned_staff_id'          => $p->assigned_staff_id,
                    'next_allowed_date'          => $p->next_allowed_date,
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
        abort_if(! $staff, 403, 'Not a staff member');

        $logs = PackageLog::query()
            ->where('service_package_id', $package->id)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $logs->map(fn (PackageLog $log) => [
                'id' => $log->id,
                'package_id' => $log->service_package_id,
                'staff_id' => $log->staff_id,
                'appointment_id' => $log->appointment_id,
                'appointment_ref' => $log->appointment_ref,
                'usage_type' => $log->usage_type,
                'quantity' => $log->quantity,
                'session_number' => $log->session_number,
                'used_sessions' => $log->used_sessions,
                'used_minutes' => $log->used_minutes,
                'occurred_on' => optional($log->occurred_on)?->toDateString(),
                'source' => $log->source,
                'created_by_id' => $log->created_by_id,
                'used_at' => optional($log->used_at)?->toISOString(),
                'note' => $log->note,
                'voided_at' => optional($log->voided_at)?->toISOString(),
                'voided_by_id' => $log->voided_by_id,
                'void_reason' => $log->void_reason,
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
