<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\PackageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MePackageController extends Controller
{
    // GET /api/v1/me/packages
    public function index(Request $request)
    {
        $user = $request->user();

        $q = ServicePackage::query()
            ->ownedBy($user->id)
            ->with(['service:id,name,slug'])
            ->latest('id');

        // Optional filters
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('type')) {
            // type=sessions | minutes
            if ($request->type === 'sessions') {
                $q->whereNotNull('remaining_sessions')->whereNull('remaining_minutes');
            } elseif ($request->type === 'minutes') {
                $q->whereNotNull('remaining_minutes')->whereNull('remaining_sessions');
            }
        }

        // Pagination (default 15)
        $packages = $q->paginate($request->integer('per_page', 15));

        // Map a clean payload
        $data = $packages->through(function (ServicePackage $p) {
        return [
            'id'      => $p->id,
            'service' => [
                'id'   => $p->service_id,
                'name' => $p->service?->name ?? $p->service_name,
            ],
            'status'    => $p->status,
            'starts_on' => optional($p->starts_on)?->toDateString(),

            // ✅ payments
            'price_total'       => (float) ($p->price_total ?? $p->price_paid ?? 0),
            'amount_paid'       => (float) $p->amount_paid,
            'remaining_payment' => (float) $p->remaining_to_pay,
            'currency'          => $p->currency,
            'sale_original_price' => $p->sale_original_price !== null ? (float) $p->sale_original_price : null,
            'sale_final_price' => $p->sale_final_price !== null ? (float) $p->sale_final_price : (float) ($p->price_total ?? 0),
            'sale_discount_amount' => $p->sale_discount_amount !== null ? (float) $p->sale_discount_amount : null,
            'sale_offer_name' => $p->sale_offer_name,

            'usage_type' => $p->usageType(),
            'total_units' => $p->totalUnits(),
            'remaining_units' => $p->remainingUnits(),
            'remaining_sessions' => $p->remaining_sessions,
            'remaining_minutes' => $p->remaining_minutes,
            'minimum_interval_days' => (int) ($p->snapshot_minimum_interval_days ?? 0),
            'staff_policy' => $p->staffPolicy(),
            'assigned_staff_id' => $p->assigned_staff_id,
            'next_allowed_date' => $p->next_allowed_date,
            'is_exhausted' => $p->isExhausted(),
        ];
    });


        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $packages->currentPage(),
                'per_page'     => $packages->perPage(),
                'total'        => $packages->total(),
            ],
        ]);
    }

    // GET /api/v1/me/packages/{package}
    public function show(Request $request, ServicePackage $package)
    {
        $user = $request->user();
        if ($package->user_id !== $user->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $package->load(['service:id,name,slug', 'logs' => function ($q) {
            $q->orderByDesc('occurred_on')->orderByDesc('id')->limit(20);
        }, 'payments' => function ($q) {
            $q->latest('id')->limit(20);
        }]);

        $giftCardPaymentIds = DB::table('gift_card_redemptions')
            ->whereIn('package_payment_id', $package->payments->pluck('id'))
            ->pluck('package_payment_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json([
            'data' => [
                'id'      => $package->id,
                'service' => [
                    'id'   => $package->service_id,
                    'name' => $package->service?->name ?? $package->service_name,
                    'slug' => $package->service?->slug,
                ],
                'status'    => $package->status,
                'starts_on' => optional($package->starts_on)?->toDateString(),

                // ✅ payments
                'price_total'       => (float) ($package->price_total ?? $package->price_paid ?? 0),
                'amount_paid'       => (float) $package->amount_paid,
                'remaining_payment' => (float) $package->remaining_to_pay,
                'currency'          => $package->currency,
                'sale_original_price' => $package->sale_original_price !== null ? (float) $package->sale_original_price : null,
                'sale_final_price' => $package->sale_final_price !== null ? (float) $package->sale_final_price : (float) ($package->price_total ?? 0),
                'sale_discount_amount' => $package->sale_discount_amount !== null ? (float) $package->sale_discount_amount : null,
                'sale_offer_name' => $package->sale_offer_name,

                'usage_type' => $package->usageType(),
                'total_units' => $package->totalUnits(),
                'remaining_units' => $package->remainingUnits(),
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes' => $package->remaining_minutes,
                'minimum_interval_days' => (int) ($package->snapshot_minimum_interval_days ?? 0),
                'deduction_method' => $package->deductionMethod(),
                'staff_policy' => $package->staffPolicy(),
                'assigned_staff_id' => $package->assigned_staff_id,
                'next_allowed_date' => $package->next_allowed_date,
                'snapshot' => [
                    'sessions' => $package->snapshot_total_sessions,
                    'minutes' => $package->snapshot_total_minutes,
                    'duration_minutes' => $package->snapshot_duration_minutes,
                ],
                'payments' => $package->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'method' => in_array((int) $payment->id, $giftCardPaymentIds, true) ? 'gift_card' : $payment->method,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                    'voided_at' => optional($payment->voided_at)?->toIso8601String(),
                ]),
                'logs' => $package->logs->map(fn (PackageLog $log) => [
                    'id' => $log->id,
                    'usage_type' => $log->usage_type,
                    'quantity' => $log->quantity,
                    'session_number' => $log->session_number,
                    'occurred_on' => optional($log->occurred_on)?->toDateString(),
                    'source' => $log->source,
                    'staff_id' => $log->staff_id,
                    'appointment_id' => $log->appointment_id,
                    'note' => $log->note,
                    'voided_at' => optional($log->voided_at)?->toDateTimeString(),
                ]),
            ],
        ]);

    }
}
