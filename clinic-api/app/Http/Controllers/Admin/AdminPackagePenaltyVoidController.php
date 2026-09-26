<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageLog;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPackagePenaltyVoidController extends Controller
{
    /** POST /api/v1/admin/packages/{package}/penalties/{log}/void */
    public function store(Request $request, ServicePackage $package, PackageLog $log): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request, $package, $log, $data) {
            // Always lock the package first, consistent with NoShowPenaltyService.
            $lockedPackage = ServicePackage::query()->lockForUpdate()->findOrFail($package->id);
            $lockedLog = PackageLog::query()->lockForUpdate()->findOrFail($log->id);

            if ((int) $lockedLog->service_package_id !== (int) $lockedPackage->id
                || $lockedLog->source !== PackageLog::SOURCE_NO_SHOW_PENALTY) {
                throw ValidationException::withMessages([
                    'penalty' => 'This record is not a no-show penalty for this package.',
                ]);
            }
            if ($lockedLog->voided_at !== null) {
                throw ValidationException::withMessages([
                    'penalty' => 'This penalty has already been voided.',
                ]);
            }

            $quantity = (int) $lockedLog->used_sessions;
            if ($lockedLog->usage_type !== 'session' || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'penalty' => 'This penalty does not contain a valid session deduction.',
                ]);
            }

            $lockedPackage->remaining_sessions = (int) $lockedPackage->remaining_sessions + $quantity;
            // Do not change paused/cancelled status as a side effect of a correction.
            if ($lockedPackage->status === ServicePackage::STATUS_EXHAUSTED) {
                $lockedPackage->status = ServicePackage::STATUS_ACTIVE;
            }
            $lockedPackage->save();

            $lockedLog->voided_at = now();
            $lockedLog->voided_by_id = $request->user()->id;
            $lockedLog->void_reason = trim($data['reason']);
            $lockedLog->save();

            return [
                'id' => $lockedLog->id,
                'voided_at' => $lockedLog->voided_at->toDateTimeString(),
                'void_reason' => $lockedLog->void_reason,
                'voided_by' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'restored_sessions' => $quantity,
                'package' => [
                    'id' => $lockedPackage->id,
                    'remaining_sessions' => (int) $lockedPackage->remaining_sessions,
                    'status' => $lockedPackage->status,
                ],
            ];
        }, 3);

        return response()->json(['data' => $result]);
    }
}
