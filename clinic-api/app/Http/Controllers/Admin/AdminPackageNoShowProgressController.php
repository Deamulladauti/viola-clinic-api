<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\NoShowPolicySetting;
use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;

class AdminPackageNoShowProgressController extends Controller
{
    public function show(ServicePackage $package): JsonResponse
    {
        $setting = NoShowPolicySetting::current();

        $threshold = max(
            1,
            (int) $setting->misses_before_penalty
        );

        $isSessionPackage =
            $package->usageType() === Service::USAGE_SESSION;

        $total = $isSessionPackage
            ? Appointment::query()
                ->where(
                    'service_package_id',
                    $package->id
                )
                ->where(
                    'status',
                    Appointment::STATUS_NO_SHOW
                )
                ->count()
            : 0;

        $thresholdsMet = intdiv($total, $threshold);

        $progress = $total === 0
            ? 0
            : (($total - 1) % $threshold) + 1;

        $penalties = PackageLog::query()
            ->where('service_package_id', $package->id)
            ->where('source', PackageLog::SOURCE_NO_SHOW_PENALTY)
            ->whereNull('voided_at');
        $penaltyCount = (clone $penalties)->count();
        $deductedSessions = (clone $penalties)->sum('used_sessions');

        return response()->json([
            'data' => [
                'package_id' => (int) $package->id,

                'client_id' => (int) $package->user_id,

                'is_applicable' => $isSessionPackage,

                'policy_enabled' =>
                    (bool) $setting->is_enabled,

                'misses_before_penalty' => $threshold,

                'sessions_to_deduct' =>
                    (int) $setting->sessions_to_deduct,

                'total_no_shows' => $total,

                'thresholds_met' => $thresholdsMet,

                'progress_in_current_threshold' =>
                    $progress,

                'remaining_in_current_threshold' =>
                    $total === 0
                        ? $threshold
                        : $threshold - $progress,

                'threshold_reached' =>
                    $thresholdsMet > 0,

                'penalty_deduction_active' => (bool) $setting->is_enabled,
                'penalties_applied' => $penaltyCount,
                'penalty_sessions_deducted' => (int) $deductedSessions,
            ],
        ]);
    }
}
