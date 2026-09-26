<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NoShowPolicySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNoShowPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->payload(NoShowPolicySetting::current()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'misses_before_penalty' => ['required', 'integer', 'min:1', 'max:20'],
            'sessions_to_deduct' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $setting = NoShowPolicySetting::current();
        $setting->fill($validated);
        $setting->updated_by_user_id = $request->user()?->id;
        $setting->save();

        return response()->json([
            'message' => 'No-show policy updated.',
            'data' => $this->payload($setting->fresh()),
        ]);
    }

    private function payload(NoShowPolicySetting $setting): array
    {
        return [
            'id' => (int) $setting->id,
            'is_enabled' => (bool) $setting->is_enabled,
            'misses_before_penalty' => (int) $setting->misses_before_penalty,
            'sessions_to_deduct' => (int) $setting->sessions_to_deduct,
            'updated_by_user_id' => $setting->updated_by_user_id ? (int) $setting->updated_by_user_id : null,
            'updated_at' => $setting->updated_at?->toISOString(),
        ];
    }
}
