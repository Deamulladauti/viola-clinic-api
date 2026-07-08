<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPackageRequest;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPackageController extends Controller
{
    /**
     * POST /api/v1/admin/packages/assign
     * Body: user_id, service_id, [price_total, currency, starts_on, expires_on, notes]
     */
    public function assign(AssignPackageRequest $request)
    {
        $service = Service::findOrFail($request->integer('service_id'));

        if (! $service->is_package) {
            return response()->json(['message' => 'Selected service is not marked as a package.'], 422);
        }

        $isSessionsType = ! is_null($service->total_sessions) && is_null($service->total_minutes);
        $isMinutesType  = ! is_null($service->total_minutes) && is_null($service->total_sessions);

        if (! $isSessionsType && ! $isMinutesType) {
            return response()->json([
                'message' => 'Package service must define either total_sessions or total_minutes (exclusively).',
            ], 422);
        }

        $priceTotal = $request->filled('price_total')
            ? (float) $request->price_total
            : (float) $service->price;

        $currency = strtoupper((string) $request->string('currency', 'EUR'));

        $pkg = ServicePackage::create([
            'user_id'                 => $request->integer('user_id'),
            'service_id'              => $service->id,
            'service_name'            => $service->name,

            'snapshot_total_sessions' => $isSessionsType ? $service->total_sessions : null,
            'snapshot_total_minutes'  => $isMinutesType ? $service->total_minutes : null,

            'price_total'             => $priceTotal,
            'currency'                => $currency,

            // legacy field; keep it as package total fallback
            'price_paid'              => $priceTotal,

            'remaining_sessions'      => $isSessionsType ? $service->total_sessions : null,
            'remaining_minutes'       => $isMinutesType ? $service->total_minutes : null,

            'status'                  => 'active',
            'starts_on'               => $request->date('starts_on'),
            'expires_on'              => $request->date('expires_on'),
            'notes'                   => $request->string('notes'),
        ]);

        return response()->json([
            'data' => [
                'id'                       => $pkg->id,
                'user_id'                  => $pkg->user_id,
                'service_id'               => $pkg->service_id,
                'service_name'             => $pkg->service_name,
                'status'                   => $pkg->status,
                'price_total'              => (float) $pkg->price_total,
                'amount_paid'              => (float) $pkg->amount_paid,
                'remaining_balance'        => (float) $pkg->remaining_to_pay,
                'amount_paid_mkd'          => (float) $pkg->amount_paid_mkd,
                'remaining_balance_mkd'    => (float) $pkg->remaining_to_pay_mkd,
                'currency'                 => $pkg->currency,
                'remaining_sessions'       => $pkg->remaining_sessions,
                'remaining_minutes'        => $pkg->remaining_minutes,
                'starts_on'                => optional($pkg->starts_on)?->toDateString(),
                'expires_on'               => optional($pkg->expires_on)?->toDateString(),
            ],
        ], 201);
    }

    /**
     * PATCH /api/v1/admin/packages/{package}/status
     * Body: status in [active, used, expired, frozen, cancelled]
     */
    public function updateStatus(ServicePackage $package)
    {
        request()->validate([
            'status' => ['required', 'in:active,used,expired,frozen,cancelled'],
        ]);

        $package->status = request('status');
        $package->save();

        return response()->json([
            'data' => [
                'id'     => $package->id,
                'status' => $package->status,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/users/{user}/packages
     */
    public function listForUser(Request $request, int $userId)
    {
        $query = ServicePackage::with('service:id,name')
            ->where('user_id', $userId);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query
            ->latest('id')
            ->get()
            ->map(function (ServicePackage $p) {
                $total = (float) ($p->price_total ?? 0);

                return [
                    'id'                       => $p->id,
                    'user_id'                  => $p->user_id,
                    'service_id'               => $p->service_id,
                    'service_name'             => $p->service?->name ?? $p->service_name,
                    'status'                   => $p->status,
                    'remaining_sessions'       => $p->remaining_sessions,
                    'remaining_minutes'        => $p->remaining_minutes,
                    'price_total'              => $total ?: null,
                    'amount_paid'              => (float) ($p->amount_paid ?? 0),
                    'remaining_balance'        => (float) ($p->remaining_to_pay ?? 0),
                    'amount_paid_mkd'          => (float) ($p->amount_paid_mkd ?? 0),
                    'remaining_balance_mkd'    => (float) ($p->remaining_to_pay_mkd ?? 0),
                    'currency'                 => $p->currency,
                    'starts_on'                => optional($p->starts_on)?->toDateString(),
                    'expires_on'               => optional($p->expires_on)?->toDateString(),
                ];
            })
            ->values();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/v1/admin/packages/{package}/payments
     *
     * Payment rules:
     * - card: always MKD, no currency needed from frontend
     * - cash: currency required, only EUR or MKD
     * - EUR cash: converted with ServicePackage::EUR_TO_MKD
     */
    public function addPayment(Request $request, ServicePackage $package)
    {
        $admin = $request->user();

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
        $remainingMkd  = (float) $package->remaining_to_pay_mkd;

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

        $payment = DB::transaction(function () use ($package, $data, $normalized, $admin) {
            return $package->payments()->create([
                'service_package_id' => $package->id,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'user_id'            => $package->user_id,
                'staff_id'           => null,
                'admin_id'           => $admin->id,
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
            'message'                => 'Payment recorded (admin).',
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
     * GET /api/v1/admin/packages/{package}/logs
     */
    public function logs(ServicePackage $package)
    {
        $items = $package->logs()
            ->latest('id')
            ->get()
            ->map(function ($log) {
                return [
                    'id'            => $log->id,
                    'used_minutes'  => $log->used_minutes,
                    'used_sessions' => $log->used_sessions,
                    'used_at'       => optional($log->used_at)?->toDateTimeString(),
                    'note'          => $log->note,
                    'created_at'    => optional($log->created_at)?->toDateTimeString(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * POST /api/v1/admin/packages/{package}/use
     * Body:
     *  - type: minutes|sessions
     *  - amount: integer >= 1
     *  - note?: string
     *  - appointment_id?: int
     */
    public function use(Request $request, ServicePackage $package)
    {
        $admin = $request->user();

        $data = $request->validate([
            'type'           => ['required', 'in:minutes,sessions'],
            'amount'         => ['required', 'integer', 'min:1'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $warning = null;
        $usedAmount = (int) $data['amount'];

        DB::transaction(function () use ($package, $data, $admin, &$warning, &$usedAmount) {
            $package->refresh();

            if ($data['type'] === 'minutes') {
                if ($package->remaining_minutes === null) {
                    abort(response()->json([
                        'ok' => false,
                        'message' => 'This package is not minutes-based.',
                    ], 422));
                }

                $before = (int) $package->remaining_minutes;

                if ($before <= 0) {
                    $warning = 'Package has no minutes remaining. Usage was recorded with 0 deducted.';
                    $usedAmount = 0;
                } elseif ($usedAmount > $before) {
                    $warning = 'Requested minutes exceeded remaining balance. Deducted only the remaining minutes.';
                    $usedAmount = $before;
                }

                $package->remaining_minutes = max(0, $before - $usedAmount);

                if ($package->remaining_minutes <= 0) {
                    $package->status = 'used';
                }

                $package->save();

                $package->logs()->create([
                    'service_package_id' => $package->id,
                    'appointment_id'     => $data['appointment_id'] ?? null,
                    'used_minutes'       => $usedAmount,
                    'used_sessions'      => null,
                    'used_at'            => now(),
                    'note'               => $data['note'] ?? null,
                    'admin_id'           => $admin->id,
                    'staff_id'           => null,
                ]);
            }

            if ($data['type'] === 'sessions') {
                if ($package->remaining_sessions === null) {
                    abort(response()->json([
                        'ok' => false,
                        'message' => 'This package is not sessions-based.',
                    ], 422));
                }

                $before = (int) $package->remaining_sessions;

                if ($before <= 0) {
                    $warning = 'Package has no sessions remaining. Usage was recorded with 0 deducted.';
                    $usedAmount = 0;
                } elseif ($usedAmount > $before) {
                    $warning = 'Requested sessions exceeded remaining balance. Deducted only the remaining sessions.';
                    $usedAmount = $before;
                }

                $package->remaining_sessions = max(0, $before - $usedAmount);

                if ($package->remaining_sessions <= 0) {
                    $package->status = 'used';
                }

                $package->save();

                $package->logs()->create([
                    'service_package_id' => $package->id,
                    'appointment_id'     => $data['appointment_id'] ?? null,
                    'used_minutes'       => null,
                    'used_sessions'      => $usedAmount,
                    'used_at'            => now(),
                    'note'               => $data['note'] ?? null,
                    'admin_id'           => $admin->id,
                    'staff_id'           => null,
                ]);
            }
        });

        $package->refresh();

        return response()->json([
            'ok'                 => true,
            'message'            => 'Package usage recorded (admin).',
            'warning'            => $warning,
            'package_id'         => $package->id,
            'status'             => $package->status,
            'remaining_minutes'  => $package->remaining_minutes,
            'remaining_sessions' => $package->remaining_sessions,
            'used_amount'        => $usedAmount,
            'type'               => $data['type'],
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