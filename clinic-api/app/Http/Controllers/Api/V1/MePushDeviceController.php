<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpoPushDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MePushDeviceController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^ExponentPushToken\[[^]\s]+\]$|^ExpoPushToken\[[^]\s]+\]$/'],
            'device_id' => ['nullable', 'string', 'max:120'],
        ]);
        DB::transaction(function () use ($request, $data) {
            // A physical installation can only belong to one signed-in account at a time.
            if (!empty($data['device_id'])) {
                ExpoPushDevice::where('device_id', $data['device_id'])->delete();
            }
            ExpoPushDevice::updateOrCreate(['token' => $data['token']], [
                'user_id' => $request->user()->id,
                'device_id' => $data['device_id'] ?? null,
            ]);
        });
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        ExpoPushDevice::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();
        return response()->json(['ok' => true]);
    }

    public function preferences(Request $request)
    {
        return response()->json(['notifications_enabled' => $request->user()->notifications_enabled !== false]);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['notifications_enabled' => ['required', 'boolean']]);
        $request->user()->update(['notifications_enabled' => $data['notifications_enabled']]);
        return response()->json(['notifications_enabled' => (bool) $request->user()->notifications_enabled]);
    }
}
