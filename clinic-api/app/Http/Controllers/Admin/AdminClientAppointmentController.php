<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClientAppointmentRequest;
use App\Models\User;
use App\Services\AdminClientAppointmentService;
use Illuminate\Http\JsonResponse;

class AdminClientAppointmentController extends Controller
{
    public function store(
        StoreClientAppointmentRequest $request,
        User $client,
        AdminClientAppointmentService $service,
    ): JsonResponse {
        $result = $service->create(
            $client,
            $request->validated(),
            $request->user(),
        );

        $appointment = $result['appointment'];
        $package = $result['package'];

        return response()->json([
            'message' => $appointment->source === 'manual_import'
                ? 'Historical visit created successfully.'
                : 'Appointment created successfully.',
            'data' => [
                'appointment' => [
                    'id' => $appointment->id,
                    'reference_code' => $appointment->reference_code,
                    'status' => $appointment->status,
                    'source' => $appointment->source,
                    'date' => $appointment->date?->toDateString(),
                    'starts_at' => $appointment->starts_at,
                    'duration_minutes' => $appointment->duration_minutes,
                    'price' => (float) $appointment->price,
                    'sale_original_price' => $appointment->sale_original_price !== null ? (float) $appointment->sale_original_price : null,
                    'sale_discount_type' => $appointment->sale_discount_type,
                    'sale_discount_value' => $appointment->sale_discount_value !== null ? (float) $appointment->sale_discount_value : null,
                    'sale_discount_amount' => $appointment->sale_discount_amount !== null ? (float) $appointment->sale_discount_amount : 0.0,
                    'sale_final_price' => $appointment->sale_final_price !== null ? (float) $appointment->sale_final_price : (float) $appointment->price,
                    'sale_offer_id' => $appointment->sale_offer_id,
                    'sale_offer_name' => $appointment->sale_offer_name,
                    'notes' => $appointment->notes,
                    'customer' => [
                        'id' => $appointment->user?->id,
                        'name' => $appointment->user?->name ?? $appointment->customer_name,
                        'email' => $appointment->user?->email ?? $appointment->customer_email,
                        'phone' => $appointment->user?->phone ?? $appointment->customer_phone,
                    ],
                    'service' => [
                        'id' => $appointment->service?->id,
                        'name' => $appointment->service?->name,
                        'usage_type' => $appointment->service?->usage_type,
                        'minimum_interval_days' => $appointment->service?->minimum_interval_days,
                        'staff_policy' => $appointment->service?->staff_policy,
                    ],
                    'staff' => $appointment->staff ? [
                        'id' => $appointment->staff->id,
                        'name' => $appointment->staff->name,
                    ] : null,
                    'service_package_id' => $appointment->service_package_id,
                ],
                'package' => $package ? [
                    'id' => $package->id,
                    'service_id' => $package->service_id,
                    'service_name' => $package->service_name,
                    'status' => $package->status,
                    'remaining_sessions' => $package->remaining_sessions,
                    'remaining_minutes' => $package->remaining_minutes,
                    'assigned_staff_id' => $package->assigned_staff_id,
                    'snapshot_usage_type' => $package->snapshot_usage_type,
                    'snapshot_minimum_interval_days' => $package->snapshot_minimum_interval_days,
                    'snapshot_staff_policy' => $package->snapshot_staff_policy,
                    'price_total' => $package->price_total !== null ? (float) $package->price_total : null,
                    'sale_original_price' => $package->sale_original_price !== null ? (float) $package->sale_original_price : null,
                    'sale_discount_type' => $package->sale_discount_type,
                    'sale_discount_value' => $package->sale_discount_value !== null ? (float) $package->sale_discount_value : null,
                    'sale_discount_amount' => $package->sale_discount_amount !== null ? (float) $package->sale_discount_amount : 0.0,
                    'sale_final_price' => $package->sale_final_price !== null ? (float) $package->sale_final_price : ($package->price_total !== null ? (float) $package->price_total : null),
                    'sale_offer_id' => $package->sale_offer_id,
                    'sale_offer_name' => $package->sale_offer_name,
                    'amount_paid' => (float) $package->amount_paid,
                    'remaining_to_pay' => (float) $package->remaining_to_pay,
                    'currency' => $package->currency,
                ] : null,
                'warnings' => $result['warnings'],
                'next_allowed_date' => $result['next_allowed_date'],
            ],
        ], 201);
    }
}
