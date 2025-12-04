<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ServicePackage;
use App\Models\PackagePayment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * POST /api/v1/appointments/{appointment}/payments
     *
     * Use this for ONE-TIME services (no package).
     * For package sessions, use the package endpoint instead.
     */
    public function storeForAppointment(Request $request, Appointment $appointment)
    {
        // Guard: for package-linked sessions, we expect payment on the package instead
        if ($appointment->service_package_id) {
            return response()->json([
                'message' => 'This appointment belongs to a package. Please record payment on the package instead.',
            ], 422);
        }

        $data = $request->validate([
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'method'   => ['required', 'string', Rule::in(['cash', 'card', 'bank', 'other'])],
            'notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        $user   = $request->user();     // who is recording the payment (staff/admin)
        $client = $appointment->user;   // may be null if guest booking, but in your app it should be set

        $payment = PackagePayment::create([
            'service_package_id' => null,
            'appointment_id'     => $appointment->id,
            'user_id'            => $client?->id,
            'staff_id'           => $user?->staff->id ?? null, // if current user has a staff record
            'admin_id'           => $user?->id,                 // or you can refine based on roles
            'method'             => $data['method'],
            'amount'             => $data['amount'],
            'currency'           => strtoupper($data['currency']),
            'notes'              => $data['notes'] ?? null,
        ]);

        // reload appointment with computed payment fields
        $appointment->loadMissing('service', 'staff', 'user');

        return response()->json([
            'message'  => 'Payment recorded for appointment.',
            'payment'  => $payment,
            'summary'  => [
                'appointment_id'    => $appointment->id,
                'service'           => $appointment->service?->name,
                'amount_paid'       => $appointment->amount_paid,
                'remaining_to_pay'  => $appointment->remaining_to_pay,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/packages/{package}/payments
     *
     * Use this for LASER / SOLARIUM packages.
     */
    public function storeForPackage(Request $request, ServicePackage $package)
    {
        $data = $request->validate([
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'method'   => ['required', 'string', Rule::in(['cash', 'card', 'bank', 'other'])],
            'notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        $user   = $request->user();       // staff/admin
        $client = $package->user;         // package owner

        $payment = PackagePayment::create([
            'service_package_id' => $package->id,
            'appointment_id'     => null,
            'user_id'            => $client?->id,
            'staff_id'           => $user?->staff->id ?? null,
            'admin_id'           => $user?->id,
            'method'             => $data['method'],
            'amount'             => $data['amount'],
            'currency'           => strtoupper($data['currency']),
            'notes'              => $data['notes'] ?? null,
        ]);

        // Refresh package so accessors see the new payment
        $package->refresh();

        return response()->json([
            'message' => 'Payment recorded for package.',
            'payment' => $payment,
            'summary' => [
                'package_id'        => $package->id,
                'service'           => $package->service_name,
                'price_total'       => $package->price_total ?? $package->price_paid,
                'amount_paid'       => $package->amount_paid,
                'remaining_to_pay'  => $package->remaining_to_pay,
            ],
        ], 201);
    }
}
