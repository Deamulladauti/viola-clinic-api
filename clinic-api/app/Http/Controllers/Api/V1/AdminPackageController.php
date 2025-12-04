<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPackageRequest;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class AdminPackageController extends Controller
{
    /**

 * POST /api/v1/admin/packages/assign
 * Body: user_id, service_id, [price_total, currency, starts_on, expires_on, notes]
 */
public function assign(AssignPackageRequest $request)
{
    $service = Service::findOrFail($request->integer('service_id'));

    if (!$service->is_package) {
        return response()->json(['message' => 'Selected service is not marked as a package.'], 422);
    }

    // Determine type and starting balances from the service definition
    $isSessionsType = !is_null($service->total_sessions) && is_null($service->total_minutes);
    $isMinutesType  = !is_null($service->total_minutes)  && is_null($service->total_sessions);

    if (!$isSessionsType && !$isMinutesType) {
        return response()->json(['message' => 'Package service must define either total_sessions or total_minutes (exclusively).'], 422);
    }

    // 🔥 New: use price_total as the full package price
    $priceTotal = $request->filled('price_total')
        ? (float) $request->price_total
        : (float) $service->price;

    $currency = $request->string('currency', 'EUR');

    $pkg = ServicePackage::create([
        'user_id'                 => $request->integer('user_id'),
        'service_id'              => $service->id,
        'service_name'            => $service->name,
        'snapshot_total_sessions' => $isSessionsType ? $service->total_sessions : null,
        'snapshot_total_minutes'  => $isMinutesType  ? $service->total_minutes  : null,

        // 💰 clean money model
        'price_total'             => $priceTotal,
        'amount_paid'             => 0,
        'currency'                => $currency,

        // (optional legacy mirror, can keep for backwards compatibility)
        'price_paid'              => $priceTotal,

        'remaining_sessions'      => $isSessionsType ? $service->total_sessions : null,
        'remaining_minutes'       => $isMinutesType  ? $service->total_minutes  : null,

        'status'                  => 'active',
        'starts_on'               => $request->date('starts_on'),
        'expires_on'              => $request->date('expires_on'),
        'notes'                   => $request->string('notes'),
    ]);

    $remaining = max(0, (float) $pkg->price_total - (float) ($pkg->amount_paid ?? 0));

    return response()->json([
        'data' => [
            'id'                 => $pkg->id,
            'user_id'            => $pkg->user_id,
            'service_id'         => $pkg->service_id,
            'service_name'       => $pkg->service_name,
            'status'             => $pkg->status,
            'price_total'        => (float) $pkg->price_total,
            'amount_paid'        => (float) ($pkg->amount_paid ?? 0),
            'remaining_balance'  => $remaining,
            'currency'           => $pkg->currency,
            'remaining_sessions' => $pkg->remaining_sessions,
            'remaining_minutes'  => $pkg->remaining_minutes,
            'starts_on'          => optional($pkg->starts_on)?->toDateString(),
            'expires_on'         => optional($pkg->expires_on)?->toDateString(),
        ]
    ], 201);
}


    /**
     * PATCH /api/v1/admin/packages/{package}/status
     * Body: status in [active, exhausted, expired, cancelled]
     */
    public function updateStatus(ServicePackage $package)
    {
        request()->validate([
            'status' => ['required','in:active,exhausted,expired,cancelled']
        ]);

        $package->status = request('status');
        $package->save();

        return response()->json(['data' => [
            'id' => $package->id,
            'status' => $package->status,
        ]]);
    }

    /**
     * Optional: GET /api/v1/admin/users/{user}/packages
     */
    public function listForUser(int $userId)
    {
        $items = ServicePackage::with('service:id,name')
            ->where('user_id', $userId)
            ->latest('id')
            ->get()
            ->map(function ($p) {
                $total     = (float) ($p->price_total ?? 0);
                $paid      = (float) ($p->amount_paid ?? 0);
                $remaining = $total > 0 ? max(0, $total - $paid) : null;

                return [
                    'id' => $p->id,
                    'service' => [
                        'id'   => $p->service_id,
                        'name' => $p->service?->name ?? $p->service_name,
                    ],
                    'status'             => $p->status,
                    'remaining_sessions' => $p->remaining_sessions,
                    'remaining_minutes'  => $p->remaining_minutes,
                    'price_total'        => $total ?: null,
                    'amount_paid'        => $paid ?: 0,
                    'remaining_balance'  => $remaining,
                    'starts_on'          => optional($p->starts_on)?->toDateString(),
                    'expires_on'         => optional($p->expires_on)?->toDateString(),
                ];
            });

        return response()->json(['data' => $items]);
    }



    public function addPayment(Request $request, ServicePackage $package)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        $priceTotal   = (float) ($package->price_total ?? 0);
        $amountPaid   = (float) ($package->amount_paid ?? 0);
        $remaining    = max(0, $priceTotal - $amountPaid);

        if ($priceTotal <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Package has no total price set.',
            ], 422);
        }

        if ($data['amount'] > $remaining + 0.01) {
            return response()->json([
                'ok'               => false,
                'message'          => 'Amount exceeds remaining balance.',
                'remaining_before' => $remaining,
            ], 422);
        }

        $package->amount_paid = $amountPaid + $data['amount'];
        $package->save();

        $newRemaining = max(0, (float)$package->price_total - (float)$package->amount_paid);

        return response()->json([
            'ok'               => true,
            'message'          => 'Payment recorded.',
            'package_id'       => $package->id,
            'price_total'      => (float) $package->price_total,
            'amount_paid'      => (float) $package->amount_paid,
            'remaining_balance'=> $newRemaining,
        ]);
    }


    
}
