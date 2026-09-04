<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPackageRequest;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\PackageLog;
use App\Models\Offer;
use App\Services\PackageUsageService;
use App\Services\ManualSalePricingService;
use App\Services\OfferPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPackageController extends Controller
{
    /**
     * POST /api/v1/admin/packages/assign
     * Body: user_id, service_id, [price_total, currency, starts_on, notes]
     */
    public function assign(AssignPackageRequest $request, ManualSalePricingService $pricing, OfferPricingService $offerPricing)
    {
        $service = Service::findOrFail($request->integer('service_id'));

        if (! $service->is_package || ! in_array($service->usage_type, [Service::USAGE_SESSION, Service::USAGE_MINUTES], true)) {
            return response()->json(['message' => 'Selected service is not configured as a package.'], 422);
        }

        $isSessionsType = $service->usage_type === Service::USAGE_SESSION;
        $isMinutesType = $service->usage_type === Service::USAGE_MINUTES;
        $includedUnits = $isSessionsType ? (int) $service->total_sessions : (int) $service->total_minutes;

        if ($includedUnits <= 0) {
            return response()->json(['message' => 'Package service must define included units.'], 422);
        }

        $offer = null;
        $saleTerms = null;

        if ($request->filled('offer_id')) {
            $offer = Offer::query()
                ->with('services:id')
                ->findOrFail($request->integer('offer_id'));
            $saleTerms = $offerPricing->resolve($offer, $service);
        } elseif (
            $request->filled('sale_discount_type')
            || $request->filled('sale_discount_value')
        ) {
            $saleTerms = $pricing->calculate(
                originalPrice: (float) ($service->price ?? 0),
                discountType: $request->input('sale_discount_type'),
                discountValue: $request->input('sale_discount_value'),
            );
        }

        // Keep the legacy price_total override compatible for older callers,
        // but the new Admin UI uses explicit fixed/percentage discount terms.
        $priceTotal = $saleTerms
            ? $saleTerms['final_price']
            : ($request->filled('price_total')
                ? (float) $request->price_total
                : (float) $service->price);

        $currency = strtoupper((string) $request->string('currency', 'EUR'));
        $assignedStaffId = $request->integer('assigned_staff_id') ?: null;

        if ($assignedStaffId && ! $service->staff()->where('staff.id', $assignedStaffId)->exists()) {
            return response()->json(['message' => 'Assigned staff is not qualified for this service.'], 422);
        }

        $packagePayload = [
            'user_id' => $request->integer('user_id'),
            'service_id' => $service->id,
            'assigned_staff_id' => $assignedStaffId,
            'service_name' => $service->name,
            'snapshot_total_sessions' => $isSessionsType ? $includedUnits : null,
            'snapshot_total_minutes' => $isMinutesType ? $includedUnits : null,
            'snapshot_usage_type' => $service->usage_type,
            'snapshot_minimum_interval_days' => (int) ($service->minimum_interval_days ?? 0),
            'snapshot_deduction_method' => $service->deduction_method,
            'snapshot_staff_policy' => $service->staff_policy,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'price_total' => $priceTotal,
            'currency' => $currency,
            'price_paid' => 0,
            'remaining_sessions' => $isSessionsType ? $includedUnits : null,
            'remaining_minutes' => $isMinutesType ? $includedUnits : null,
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => $request->date('starts_on'),
            // Package expiry is no longer part of the clinic product rules.
            'expires_on' => null,
            'notes' => $request->string('notes'),
        ];

        if ($saleTerms) {
            $packagePayload = array_merge($packagePayload, [
                'sale_original_price' => $saleTerms['original_price'],
                'sale_discount_type' => $saleTerms['discount_type'],
                'sale_discount_value' => $saleTerms['discount_value'],
                'sale_discount_amount' => $saleTerms['discount_amount'],
                'sale_final_price' => $saleTerms['final_price'],
                'sale_offer_id' => $offer?->id,
                'sale_offer_name' => $offer?->name,
            ]);
        }

        $pkg = ServicePackage::create($packagePayload);

        return response()->json([
            'data' => [
                'id' => $pkg->id,
                'user_id' => $pkg->user_id,
                'service_id' => $pkg->service_id,
                'service_name' => $pkg->service_name,
                'status' => $pkg->status,
                'usage_type' => $pkg->snapshot_usage_type,
                'minimum_interval_days' => $pkg->snapshot_minimum_interval_days,
                'deduction_method' => $pkg->snapshot_deduction_method,
                'staff_policy' => $pkg->snapshot_staff_policy,
                'assigned_staff_id' => $pkg->assigned_staff_id,
                'price_total' => (float) $pkg->price_total,
                'sale_original_price' => (float) ($pkg->sale_original_price ?? $pkg->price_total ?? 0),
                'sale_discount_type' => $pkg->sale_discount_type,
                'sale_discount_value' => $pkg->sale_discount_value !== null ? (float) $pkg->sale_discount_value : null,
                'sale_discount_amount' => (float) ($pkg->sale_discount_amount ?? 0),
                'sale_final_price' => (float) ($pkg->sale_final_price ?? $pkg->price_total ?? 0),
                'sale_offer_id' => $pkg->sale_offer_id,
                'sale_offer_name' => $pkg->sale_offer_name,
                'amount_paid' => (float) $pkg->amount_paid,
                'remaining_balance' => (float) $pkg->remaining_to_pay,
                'amount_paid_mkd' => (float) $pkg->amount_paid_mkd,
                'remaining_balance_mkd' => (float) $pkg->remaining_to_pay_mkd,
                'currency' => $pkg->currency,
                'remaining_sessions' => $pkg->remaining_sessions,
                'remaining_minutes' => $pkg->remaining_minutes,
                'starts_on' => optional($pkg->starts_on)?->toDateString(),
                'expires_on' => optional($pkg->expires_on)?->toDateString(),
            ],
        ], 201);
    }

    public function updateStatus(ServicePackage $package)
    {
        request()->validate([
            'status' => ['required', Rule::in(ServicePackage::statuses())],
        ]);

        $package->status = request('status');
        $package->save();

        return response()->json([
            'data' => [
                'id' => $package->id,
                'status' => $package->status,
            ],
        ]);
    }

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
                    'usage_type'               => $p->snapshot_usage_type,
                    'minimum_interval_days'     => $p->snapshot_minimum_interval_days,
                    'deduction_method'          => $p->snapshot_deduction_method,
                    'staff_policy'              => $p->snapshot_staff_policy,
                    'assigned_staff_id'         => $p->assigned_staff_id,
                    'next_allowed_date'         => $p->next_allowed_date,
                    'remaining_sessions'       => $p->remaining_sessions,
                    'remaining_minutes'        => $p->remaining_minutes,
                    'price_total'              => $total ?: null,
                    'sale_original_price'       => $p->sale_original_price !== null ? (float) $p->sale_original_price : null,
                    'sale_discount_type'        => $p->sale_discount_type,
                    'sale_discount_value'       => $p->sale_discount_value !== null ? (float) $p->sale_discount_value : null,
                    'sale_discount_amount'      => (float) ($p->sale_discount_amount ?? 0),
                    'sale_final_price'          => $p->sale_final_price !== null ? (float) $p->sale_final_price : $total,
                    'sale_offer_id'             => $p->sale_offer_id,
                    'sale_offer_name'           => $p->sale_offer_name,
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
            ->with(['staff:id,name', 'appointment:id,reference_code'])
            ->latest('id')
            ->get()
            ->map(function (PackageLog $log) {
                return [
                    'id' => $log->id,
                    'usage_type' => $log->usage_type,
                    'quantity' => $log->quantity,
                    'session_number' => $log->session_number,
                    'used_minutes' => $log->used_minutes,
                    'used_sessions' => $log->used_sessions,
                    'occurred_on' => optional($log->occurred_on)?->toDateString(),
                    'used_at' => optional($log->used_at)?->toDateTimeString(),
                    'source' => $log->source,
                    'staff' => $log->staff ? ['id' => $log->staff->id, 'name' => $log->staff->name] : null,
                    'appointment_id' => $log->appointment_id,
                    'note' => $log->note,
                    'voided_at' => optional($log->voided_at)?->toDateTimeString(),
                    'void_reason' => $log->void_reason,
                    'created_at' => optional($log->created_at)?->toDateTimeString(),
                ];
            })
            ->values();

        return response()->json(['data' => $items]);
    }

    public function use(Request $request, ServicePackage $package, PackageUsageService $usageService)
    {
        $admin = $request->user();

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['minutes'])],
            'amount' => ['required', 'integer', 'min:1'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = $usageService->recordManualQuantityUsage(
            package: $package,
            quantity: (int) $data['amount'],
            occurredOn: $data['date'] ?? now()->toDateString(),
            staffId: null,
            actorUserId: $admin?->id,
            note: $data['note'] ?? null,
            source: PackageLog::SOURCE_MANUAL,
        );

        $package->refresh();

        return response()->json([
            'ok' => true,
            'message' => 'Quantity package usage recorded.',
            'package_id' => $package->id,
            'status' => $package->status,
            'remaining_minutes' => $package->remaining_minutes,
            'remaining_sessions' => $package->remaining_sessions,
            'usage' => [
                'id' => $log->id,
                'type' => $log->usage_type,
                'amount' => $log->quantity,
                'occurred_on' => optional($log->occurred_on)?->toDateString(),
            ],
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