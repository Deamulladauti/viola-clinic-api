<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

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
                'id'                 => $p->id,
                'service'            => [
                    'id'   => $p->service_id,
                    'name' => $p->service?->name ?? $p->service_name,
                ],
                'status'             => $p->status,
                'starts_on'          => optional($p->starts_on)?->toDateString(),
                'expires_on'         => optional($p->expires_on)?->toDateString(),
                'price_paid'         => $p->price_paid,
                'currency'           => $p->currency,
                'remaining_sessions' => $p->remaining_sessions,
                'remaining_minutes'  => $p->remaining_minutes,
                'is_exhausted'       => $p->isExhausted(),
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
            $q->latest('used_at')->limit(20);
        }]);

        return response()->json([
            'data' => [
                'id'                 => $package->id,
                'service'            => [
                    'id'   => $package->service_id,
                    'name' => $package->service?->name ?? $package->service_name,
                    'slug' => $package->service?->slug,
                ],
                'status'             => $package->status,
                'starts_on'          => optional($package->starts_on)?->toDateString(),
                'expires_on'         => optional($package->expires_on)?->toDateString(),
                'price_paid'         => $package->price_paid,
                'currency'           => $package->currency,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_minutes'  => $package->remaining_minutes,
                'snapshot'           => [
                    'sessions' => $package->snapshot_total_sessions,
                    'minutes'  => $package->snapshot_total_minutes,
                ],
                'logs'               => $package->logs->map(fn($log) => [
                    'id'            => $log->id,
                    'used_sessions' => $log->used_sessions,
                    'used_minutes'  => $log->used_minutes,
                    'used_at'       => optional($log->used_at)?->toDateTimeString(),
                    'staff_id'      => $log->staff_id,
                    'note'          => $log->note,
                ]),
            ],
        ]);
    }
}
