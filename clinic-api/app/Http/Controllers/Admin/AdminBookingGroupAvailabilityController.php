<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingGroupAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingGroupAvailabilityController extends Controller
{
    public function index(Request $request, BookingGroupAvailabilityService $service): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'service_ids' => ['required', 'array', 'min:2', 'max:10'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'step' => ['nullable', 'integer', 'in:5,10,15,20,30,60'],
        ]);

        $result = $service->availableSlots(
            array_map('intval', $data['service_ids']),
            (int) $data['staff_id'],
            (string) $data['date'],
            (int) ($data['step'] ?? 15),
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
