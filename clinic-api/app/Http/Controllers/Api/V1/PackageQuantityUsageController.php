<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPackageQuantityUsageRequest;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Services\PackageQuantityUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PackageQuantityUsageController extends Controller
{
    public function storeAdmin(
        RecordPackageQuantityUsageRequest $request,
        ServicePackage $package,
        PackageQuantityUsageService $service,
    ): JsonResponse {
        $this->rejectSessionPayload($request->input('type'));

        $staff = $request->filled('staff_id')
            ? Staff::query()->findOrFail((int) $request->validated('staff_id'))
            : null;

        $log = $service->record(
            $package,
            $request->minutes(),
            $request->occurredOn(),
            $staff,
            $request->user(),
            $request->validated('note'),
            $request->validated('source', 'manual'),
        );

        return $this->response($log, 'Package quantity usage recorded successfully.');
    }

    public function storeStaff(
        RecordPackageQuantityUsageRequest $request,
        ServicePackage $package,
        PackageQuantityUsageService $service,
    ): JsonResponse {
        $this->rejectSessionPayload($request->input('type'));

        $staff = $request->user()?->staff;

        if (!$staff) {
            abort(403, 'Not a staff member.');
        }

        $log = $service->record(
            $package,
            $request->minutes(),
            $request->occurredOn(),
            $staff,
            $request->user(),
            $request->validated('note'),
            $request->validated('source', 'manual'),
        );

        return $this->response($log, 'Package quantity usage recorded successfully.');
    }

    private function rejectSessionPayload(mixed $type): void
    {
        if (in_array($type, ['session', 'sessions'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Session packages cannot be deducted manually. Complete the linked appointment instead.',
            ]);
        }
    }

    private function response($log, string $message): JsonResponse
    {
        $package = $log->package;

        return response()->json([
            // Legacy top-level fields are retained because the current Expo
            // app still calls /use and types this response with the old shape.
            'ok' => true,
            'message' => $message,
            'warning' => null,
            'package_id' => $package->id,
            'status' => $package->status,
            'remaining_minutes' => $package->remaining_minutes,
            'remaining_sessions' => $package->remaining_sessions,
            'used_amount' => $log->quantity,
            'type' => 'minutes',
            'data' => [
                'usage' => [
                    'id' => $log->id,
                    'usage_type' => $log->usage_type,
                    'quantity' => $log->quantity,
                    'used_minutes' => $log->used_minutes,
                    'occurred_on' => Carbon::parse($log->occurred_on)->toDateString(),
                    'source' => $log->source,
                    'note' => $log->note,
                    'staff' => $log->staff ? [
                        'id' => $log->staff->id,
                        'name' => $log->staff->name,
                    ] : null,
                ],
                'package' => [
                    'id' => $package->id,
                    'status' => $package->status,
                    'remaining_minutes' => $package->remaining_minutes,
                    'remaining_sessions' => $package->remaining_sessions,
                ],
            ],
        ], 201);
    }
}
