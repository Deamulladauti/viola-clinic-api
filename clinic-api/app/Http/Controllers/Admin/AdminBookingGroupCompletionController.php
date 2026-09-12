<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Services\AdminBookingGroupCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingGroupCompletionController extends Controller
{
    public function update(
        Request $request,
        BookingGroup $bookingGroup,
        AdminBookingGroupCompletionService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $result = $service->complete(
            $bookingGroup,
            $request->user(),
            $validated['note'] ?? null,
        );

        $group = $result['group'];
        $appointments = $result['appointments'];

        return response()->json([
            'message' => 'Joined visit completed successfully.',
            'data' => [
                'booking_group' => [
                    'id' => $group->id,
                    'client_id' => $group->user_id,
                    'treatment_count' => $appointments->count(),
                    'completed_count' => $appointments->where('status', Appointment::STATUS_COMPLETED)->count(),
                    'newly_completed_count' => (int) $result['newly_completed_count'],
                    'all_completed' => $appointments->every(
                        fn (Appointment $appointment) => $appointment->status === Appointment::STATUS_COMPLETED,
                    ),
                    'appointment_ids' => $appointments->pluck('id')->values()->all(),
                ],
                'appointments' => $appointments->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'booking_group_id' => $appointment->booking_group_id,
                    'status' => $appointment->status,
                    'service_package_id' => $appointment->service_package_id,
                    'date' => $appointment->date?->toDateString(),
                    'starts_at' => (string) $appointment->starts_at,
                    'service' => $appointment->service ? [
                        'id' => $appointment->service->id,
                        'name' => $appointment->service->name,
                    ] : null,
                    'staff' => $appointment->staff ? [
                        'id' => $appointment->staff->id,
                        'name' => $appointment->staff->name,
                    ] : null,
                    'package' => $appointment->package ? [
                        'id' => $appointment->package->id,
                        'remaining_sessions' => $appointment->package->remaining_sessions,
                        'status' => $appointment->package->status,
                    ] : null,
                ])->values()->all(),
                'usage' => $result['usage'],
            ],
        ]);
    }
}
