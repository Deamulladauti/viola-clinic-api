<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPackageRequest;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            return response()->json([
                'message' => 'Package service must define either total_sessions or total_minutes (exclusively).'
            ], 422);
        }

        // Full package price
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

            // ✅ money model (payments are logged in payments table; amount_paid accessor reads them)
            'price_total'             => $priceTotal,
            'currency'                => $currency,

            // optional legacy mirror (keep if your app still reads it somewhere)
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
            ],
        ], 201);
    }

    /**
     * PATCH /api/v1/admin/packages/{package}/status
     * Body: status in [active, used, expired, frozen, cancelled]
     *
     * NOTE: keep this list aligned with your DB enum/allowed statuses.
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
     * (If you route it like that, accept $userId here.)
     */
    public function listForUser(int $userId)
    {
        $items = ServicePackage::with('service:id,name')
            ->where('user_id', $userId)
            ->latest('id')
            ->get()
            ->map(function (ServicePackage $p) {
                $total     = (float) ($p->price_total ?? 0);
                $paid      = (float) ($p->amount_paid ?? 0); // accessor (sum of payments)
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

    /**
     * POST /api/v1/admin/packages/{package}/payments
     * Admin adds a payment toward this package.
     *
     * Body:
     *  - amount: numeric >= 0.01
     *  - method: cash|card|bank|other
     *  - note?: string
     *  - appointment_id?: int (optional link)
     */
    public function addPayment(Request $request, ServicePackage $package)
    {
        $admin = $request->user();

        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'method'         => ['required', 'in:cash,card,bank,other'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        // Total price for this package
        $priceTotal   = (float) ($package->price_total ?? $package->price_paid ?? 0);
        $alreadyPaid  = (float) $package->amount_paid; // accessor on ServicePackage
        $remaining    = max(0, $priceTotal - $alreadyPaid);

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

        DB::transaction(function () use ($package, $data, $admin) {
            // IMPORTANT: create a payment row (do NOT manually increment amount_paid)
            $package->payments()->create([
                'service_package_id' => $package->id,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'user_id'            => $package->user_id,      // owner of package
                'staff_id'           => null,
                'admin_id'           => $admin->id,
                'method'             => $data['method'],
                'amount'             => $data['amount'],
                'currency'           => $package->currency ?? 'EUR',
                'notes'              => $data['note'] ?? null,
            ]);
        });

        $package->refresh();

        return response()->json([
            'ok'                => true,
            'message'           => 'Payment recorded (admin).',
            'package_id'        => $package->id,
            'price_total'       => (float) ($package->price_total ?? 0),
            'amount_paid'       => (float) $package->amount_paid,
            'remaining_balance' => (float) $package->remaining_to_pay,
        ]);
    }
}
