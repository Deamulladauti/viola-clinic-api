<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * GET /api/v1/admin/clients/{client}/payments
     *
     * Admin-only payment history for one client.
     * Includes package payments, single appointment payments, and manual client payments.
     */
    public function listForClient(Request $request, User $client)
    {
        $data = $request->validate([
            'method' => ['sometimes', 'nullable', Rule::in(['cash', 'card'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'voided', 'all'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query = PackagePayment::query()
            ->with([
                'package:id,user_id,service_id,service_name,price_total,currency,status',
                'appointment:id,user_id,service_id,service_package_id,date,starts_at,price,status,reference_code',
                'appointment.service:id,name',
                'staff:id,name,email,phone,user_id',
                'admin:id,name,email',
                'voidedBy:id,name,email',
            ])
            ->where('user_id', $client->id);

        if (!empty($data['method'])) {
            $query->where('method', $data['method']);
        }

        $status = $data['status'] ?? 'all';
        if ($status === 'active') {
            $query->whereNull('voided_at');
        } elseif ($status === 'voided') {
            $query->whereNotNull('voided_at');
        }

        if (!empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }

        if (!empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }

        $payments = $query
            ->latest('id')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $payments->map(fn (PackagePayment $payment) => $this->presentPayment($payment))->values(),
        ]);
    }

    /**
     * POST /api/v1/admin/clients/{client}/payments
     *
     * Admin manual/general client payment.
     * This is not linked to a package or appointment.
     */
    public function storeForClient(Request $request, User $client)
    {
        $admin = $request->user();

        $data = $request->validate($this->paymentValidationRules($request, 2000));
        $normalized = $this->normalizePaymentData($data);
        $note = $data['notes'] ?? $data['note'] ?? null;

        $payment = PackagePayment::create([
            'service_package_id' => null,
            'appointment_id'     => null,
            'user_id'            => $client->id,
            'staff_id'           => null,
            'admin_id'           => $admin?->id,
            'method'             => $normalized['method'],
            'amount'             => round((float) $data['amount'], 2),
            'currency'           => $normalized['currency'],
            'exchange_rate'      => $normalized['exchange_rate'],
            'amount_mkd'         => $normalized['amount_mkd'],
            'notes'              => $note,
        ]);

        $payment->loadMissing(['staff', 'admin', 'voidedBy', 'package', 'appointment.service']);

        return response()->json([
            'message' => 'Manual client payment recorded.',
            'payment' => $this->presentPayment($payment),
        ], 201);
    }

    /**
     * POST /api/v1/admin/appointments/{appointment}/payments
     * POST /api/v1/staff/appointments/{appointment}/payments
     *
     * Use this for ONE-TIME services only.
     * Package-linked appointments must be paid through the package.
     */
    public function storeForAppointment(Request $request, Appointment $appointment)
    {
        if ($appointment->service_package_id) {
            return response()->json([
                'message' => 'This appointment belongs to a package. Please record payment on the package instead.',
            ], 422);
        }

        $data = $request->validate($this->paymentValidationRules($request, 2000));
        $normalized = $this->normalizePaymentData($data);
        $note = $data['notes'] ?? $data['note'] ?? null;

        $remainingMkd = $this->appointmentRemainingMkd($appointment);

        if ($remainingMkd <= 0) {
            return response()->json([
                'message' => 'This appointment is already fully paid.',
                'remaining_mkd' => 0,
            ], 422);
        }

        if ($normalized['amount_mkd'] > $remainingMkd + 0.01) {
            return response()->json([
                'message' => 'Amount exceeds remaining appointment balance.',
                'remaining_before' => $this->mkdToEur($remainingMkd),
                'remaining_before_mkd' => $remainingMkd,
            ], 422);
        }

        $user = $request->user();
        $client = $appointment->user;

        $payment = PackagePayment::create([
            'service_package_id' => null,
            'appointment_id'     => $appointment->id,
            'user_id'            => $client?->id,
            'staff_id'           => $user?->staff?->id,
            'admin_id'           => $user?->id,
            'method'             => $normalized['method'],
            'amount'             => round((float) $data['amount'], 2),
            'currency'           => $normalized['currency'],
            'exchange_rate'      => $normalized['exchange_rate'],
            'amount_mkd'         => $normalized['amount_mkd'],
            'notes'              => $note,
        ]);

        $appointment->loadMissing('service', 'staff', 'user');

        return response()->json([
            'message' => 'Payment recorded for appointment.',
            'payment' => $this->presentPayment($payment->loadMissing(['staff', 'admin', 'voidedBy', 'package', 'appointment.service'])),
            'summary' => [
                'appointment_id' => $appointment->id,
                'service' => $appointment->service?->name,
                'price' => (float) ($appointment->price ?? 0),
                'price_mkd' => $this->appointmentPriceMkd($appointment),
                'amount_paid' => $this->mkdToEur($this->appointmentPaidMkd($appointment)),
                'amount_paid_mkd' => $this->appointmentPaidMkd($appointment),
                'remaining_to_pay' => $this->mkdToEur($this->appointmentRemainingMkd($appointment)),
                'remaining_to_pay_mkd' => $this->appointmentRemainingMkd($appointment),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/packages/{package}/payments
     *
     * Shared generic package payment method if you later route package payments here.
     * AdminPackageController and StaffPackageController may still handle package payments directly.
     */
    public function storeForPackage(Request $request, ServicePackage $package)
    {
        $data = $request->validate($this->paymentValidationRules($request, 2000));
        $normalized = $this->normalizePaymentData($data);
        $note = $data['notes'] ?? $data['note'] ?? null;

        $remainingMkd = (float) $package->remaining_to_pay_mkd;

        if ($package->priceTotalMkd() <= 0) {
            return response()->json([
                'message' => 'Package has no total price set.',
            ], 422);
        }

        if ($normalized['amount_mkd'] > $remainingMkd + 0.01) {
            return response()->json([
                'message' => 'Amount exceeds remaining package balance.',
                'remaining_before' => (float) $package->remaining_to_pay,
                'remaining_before_mkd' => $remainingMkd,
                'package_currency' => $package->packageCurrency(),
            ], 422);
        }

        $user = $request->user();
        $client = $package->user;

        $payment = PackagePayment::create([
            'service_package_id' => $package->id,
            'appointment_id'     => null,
            'user_id'            => $client?->id,
            'staff_id'           => $user?->staff?->id,
            'admin_id'           => $user?->id,
            'method'             => $normalized['method'],
            'amount'             => round((float) $data['amount'], 2),
            'currency'           => $normalized['currency'],
            'exchange_rate'      => $normalized['exchange_rate'],
            'amount_mkd'         => $normalized['amount_mkd'],
            'notes'              => $note,
        ]);

        $package->refresh();

        return response()->json([
            'message' => 'Payment recorded for package.',
            'payment' => $this->presentPayment($payment->loadMissing(['staff', 'admin', 'voidedBy', 'package', 'appointment.service'])),
            'summary' => [
                'package_id' => $package->id,
                'service' => $package->service_name,
                'price_total' => (float) ($package->price_total ?? $package->price_paid),
                'price_total_mkd' => $package->priceTotalMkd(),
                'amount_paid' => (float) $package->amount_paid,
                'amount_paid_mkd' => (float) $package->amount_paid_mkd,
                'remaining_to_pay' => (float) $package->remaining_to_pay,
                'remaining_to_pay_mkd' => (float) $package->remaining_to_pay_mkd,
                'currency' => $package->currency,
            ],
        ], 201);
    }

    /**
     * PATCH /api/v1/admin/payments/{payment}/void
     * Admin can void any non-voided payment.
     */
    public function voidByAdmin(Request $request, PackagePayment $payment)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->voided_at) {
            return response()->json([
                'message' => 'Payment is already voided.',
            ], 422);
        }

        $payment->forceFill([
            'voided_at' => now(),
            'voided_by_id' => $request->user()?->id,
            'void_reason' => $data['reason'],
        ])->save();

        $payment->loadMissing(['staff', 'admin', 'voidedBy', 'package', 'appointment.service']);

        return response()->json([
            'message' => 'Payment voided by admin.',
            'payment' => $this->presentPayment($payment),
        ]);
    }

    /**
     * PATCH /api/v1/staff/payments/{payment}/void
     * Staff can void only their own payment from today.
     */
    public function voidByStaff(Request $request, PackagePayment $payment)
    {
        $staff = $request->user()?->staff;
        abort_if(!$staff, 403, 'Not a staff member');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($payment->voided_at) {
            return response()->json([
                'message' => 'Payment is already voided.',
            ], 422);
        }

        if ((int) $payment->staff_id !== (int) $staff->id) {
            return response()->json([
                'message' => 'Staff can only void payments they created.',
            ], 403);
        }

        if (!$payment->created_at || !$payment->created_at->isSameDay(now())) {
            return response()->json([
                'message' => 'Staff can only void same-day payment mistakes. Please contact admin.',
            ], 403);
        }

        $payment->forceFill([
            'voided_at' => now(),
            'voided_by_id' => $request->user()?->id,
            'void_reason' => $data['reason'],
        ])->save();

        $payment->loadMissing(['staff', 'admin', 'voidedBy', 'package', 'appointment.service']);

        return response()->json([
            'message' => 'Payment voided by staff.',
            'payment' => $this->presentPayment($payment),
        ]);
    }

    private function paymentValidationRules(Request $request, int $noteMax = 1000): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'card'])],
            'currency' => [
                'nullable',
                'string',
                'size:3',
                Rule::requiredIf(fn () => $request->input('method') === 'cash'),
                Rule::in(['EUR', 'MKD']),
            ],
            'note' => ['nullable', 'string', 'max:' . $noteMax],
            'notes' => ['nullable', 'string', 'max:' . $noteMax],
        ];
    }

    private function normalizePaymentData(array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        $method = strtolower((string) $data['method']);

        if ($method === 'card') {
            return [
                'method' => 'card',
                'currency' => 'MKD',
                'exchange_rate' => null,
                'amount_mkd' => $amount,
            ];
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'MKD'));

        if ($currency === 'EUR') {
            return [
                'method' => 'cash',
                'currency' => 'EUR',
                'exchange_rate' => ServicePackage::EUR_TO_MKD,
                'amount_mkd' => round($amount * ServicePackage::EUR_TO_MKD, 2),
            ];
        }

        return [
            'method' => 'cash',
            'currency' => 'MKD',
            'exchange_rate' => null,
            'amount_mkd' => $amount,
        ];
    }

    private function presentPayment(PackagePayment $payment): array
    {
        $source = 'manual';
        if ($payment->service_package_id) {
            $source = 'package';
        } elseif ($payment->appointment_id) {
            $source = 'appointment';
        }

        $recordedBy = null;
        if ($payment->staff) {
            $recordedBy = [
                'type' => 'staff',
                'id' => $payment->staff->id,
                'name' => $payment->staff->name,
                'email' => $payment->staff->email,
            ];
        } elseif ($payment->admin) {
            $recordedBy = [
                'type' => 'admin',
                'id' => $payment->admin->id,
                'name' => $payment->admin->name,
                'email' => $payment->admin->email,
            ];
        }

        return [
            'id' => $payment->id,
            'source' => $source,
            'method' => $payment->method,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
            'amount_mkd' => $payment->amount_mkd !== null ? (float) $payment->amount_mkd : null,
            'notes' => $payment->notes,
            'recorded_by' => $recordedBy,
            'created_at' => optional($payment->created_at)?->toDateTimeString(),
            'is_voided' => $payment->voided_at !== null,
            'voided_at' => optional($payment->voided_at)?->toDateTimeString(),
            'void_reason' => $payment->void_reason,
            'voided_by' => $payment->voidedBy ? [
                'id' => $payment->voidedBy->id,
                'name' => $payment->voidedBy->name,
                'email' => $payment->voidedBy->email,
            ] : null,
            'package' => $payment->package ? [
                'id' => $payment->package->id,
                'service_name' => $payment->package->service_name,
                'price_total' => (float) ($payment->package->price_total ?? 0),
                'amount_paid' => (float) ($payment->package->amount_paid ?? 0),
                'remaining_balance' => (float) ($payment->package->remaining_to_pay ?? 0),
                'amount_paid_mkd' => (float) ($payment->package->amount_paid_mkd ?? 0),
                'remaining_balance_mkd' => (float) ($payment->package->remaining_to_pay_mkd ?? 0),
                'currency' => $payment->package->currency,
                'status' => $payment->package->status,
            ] : null,
            'appointment' => $payment->appointment ? [
                'id' => $payment->appointment->id,
                'service_name' => $payment->appointment->service?->name,
                'date' => $payment->appointment->date,
                'starts_at' => $payment->appointment->starts_at,
                'status' => $payment->appointment->status,
                'price' => (float) ($payment->appointment->price ?? 0),
            ] : null,
        ];
    }

    private function appointmentPriceMkd(Appointment $appointment): float
    {
        $price = (float) ($appointment->price ?? 0);

        return round($price * ServicePackage::EUR_TO_MKD, 2);
    }

    private function appointmentPaidMkd(Appointment $appointment): float
    {
        return round(
            PackagePayment::query()
                ->notVoided()
                ->where('appointment_id', $appointment->id)
                ->get()
                ->sum(fn (PackagePayment $payment) => $this->paymentAmountToMkd($payment)),
            2
        );
    }

    private function appointmentRemainingMkd(Appointment $appointment): float
    {
        return round(max($this->appointmentPriceMkd($appointment) - $this->appointmentPaidMkd($appointment), 0), 2);
    }

    private function paymentAmountToMkd(PackagePayment $payment): float
    {
        if ($payment->amount_mkd !== null) {
            return (float) $payment->amount_mkd;
        }

        $amount = (float) $payment->amount;
        $currency = strtoupper($payment->currency ?: 'EUR');

        if ($currency === 'EUR') {
            $rate = (float) ($payment->exchange_rate ?: ServicePackage::EUR_TO_MKD);
            return round($amount * $rate, 2);
        }

        return round($amount, 2);
    }

    private function mkdToEur(float $amountMkd): float
    {
        return round($amountMkd / ServicePackage::EUR_TO_MKD, 2);
    }
}
