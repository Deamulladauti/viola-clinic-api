<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClientBookingGroupRequest;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AdminBookingGroupService;
use Illuminate\Http\JsonResponse;

class AdminClientBookingGroupController extends Controller
{
    public function store(
        StoreClientBookingGroupRequest $request,
        User $client,
        AdminBookingGroupService $service,
    ): JsonResponse {
        $result = $service->create(
            $client,
            $request->validated(),
            $request->user(),
        );

        $group = $result['group'];
        $appointments = $group->appointments;
        $historical = $appointments->every(
            fn (Appointment $appointment) => $appointment->source === 'manual_import'
        );

        return response()->json([
            'message' => $historical
                ? 'Historical joined visit created successfully.'
                : 'Joined booking created successfully.',
            'data' => [
                'booking_group' => [
                    'id' => $group->id,
                    'client_id' => $group->user_id,
                    'created_by_user_id' => $group->created_by_user_id,
                    'date' => $appointments->first()?->date?->toDateString(),
                    'starts_at' => $appointments->first()?->starts_at,
                    'treatment_count' => $appointments->count(),
                    'total_duration_minutes' => (int) $result['total_duration_minutes'],
                    'appointment_ids' => $appointments->pluck('id')->values()->all(),
                ],
                'appointments' => $appointments->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'booking_group_id' => $appointment->booking_group_id,
                    'reference_code' => $appointment->reference_code,
                    'status' => $appointment->status,
                    'source' => $appointment->source,
                    'date' => $appointment->date?->toDateString(),
                    'starts_at' => $appointment->starts_at,
                    'duration_minutes' => (int) $appointment->duration_minutes,
                    'price' => (float) $appointment->price,
                    'sale_final_price' => $appointment->sale_final_price !== null
                        ? (float) $appointment->sale_final_price
                        : (float) $appointment->price,
                    'service_package_id' => $appointment->service_package_id,
                    'service' => $appointment->service ? [
                        'id' => $appointment->service->id,
                        'name' => $appointment->service->name,
                    ] : null,
                    'staff' => $appointment->staff ? [
                        'id' => $appointment->staff->id,
                        'name' => $appointment->staff->name,
                    ] : null,
                ])->values()->all(),
                'warnings' => $result['warnings'],
            ],
        ], 201);
    }
}
