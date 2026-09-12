<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookingGroupStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingGroupStaffController extends Controller
{
    public function index(Request $request, BookingGroupStaffService $service): JsonResponse
    {
        $data = $request->validate([
            'service_ids' => ['required', 'array', 'min:2', 'max:10'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'package_ids' => ['nullable', 'array', 'max:10'],
            'package_ids.*' => ['required', 'integer', 'distinct', 'exists:service_packages,id'],
        ]);

        return response()->json([
            'data' => $service->eligibleStaff(
                array_map('intval', $data['service_ids']),
                array_map('intval', $data['package_ids'] ?? []),
            ),
        ]);
    }
}
