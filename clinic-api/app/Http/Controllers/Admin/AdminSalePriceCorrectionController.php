<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\SalePriceCorrection;
use App\Models\ServicePackage;
use App\Services\SalePriceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSalePriceCorrectionController extends Controller
{
    public function correctAppointment(
        Request $request,
        Appointment $appointment,
        SalePriceCorrectionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'final_price' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $correction = $service->correctAppointment(
            $appointment,
            (float) $data['final_price'],
            trim((string) $data['reason']),
            $request->user(),
        );

        $appointment->refresh();

        return response()->json([
            'message' => 'Historical appointment sale price corrected.',
            'data' => [
                'appointment_id' => $appointment->id,
                'sale_original_price' => (float) ($appointment->sale_original_price ?? $appointment->price ?? 0),
                'sale_discount_type' => $appointment->sale_discount_type,
                'sale_discount_value' => $appointment->sale_discount_value !== null ? (float) $appointment->sale_discount_value : null,
                'sale_discount_amount' => (float) ($appointment->sale_discount_amount ?? 0),
                'sale_final_price' => $appointment->finalSalePrice(),
                'amount_paid' => (float) $correction->amount_paid_at_correction,
                'remaining_balance' => max($appointment->finalSalePrice() - (float) $correction->amount_paid_at_correction, 0),
                'correction' => $this->present($correction->load('correctedBy:id,name')),
            ],
        ]);
    }

    public function correctPackage(
        Request $request,
        ServicePackage $package,
        SalePriceCorrectionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'final_price' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $correction = $service->correctPackage(
            $package,
            (float) $data['final_price'],
            trim((string) $data['reason']),
            $request->user(),
        );

        $package->refresh();

        return response()->json([
            'message' => 'Historical package sale price corrected.',
            'data' => [
                'package_id' => $package->id,
                'sale_original_price' => (float) ($package->sale_original_price ?? $package->price_total ?? 0),
                'sale_discount_type' => $package->sale_discount_type,
                'sale_discount_value' => $package->sale_discount_value !== null ? (float) $package->sale_discount_value : null,
                'sale_discount_amount' => (float) ($package->sale_discount_amount ?? 0),
                'sale_final_price' => $package->finalSalePrice(),
                'amount_paid' => (float) $package->amount_paid,
                'remaining_balance' => (float) $package->remaining_to_pay,
                'currency' => $package->packageCurrency(),
                'correction' => $this->present($correction->load('correctedBy:id,name')),
            ],
        ]);
    }

    public function appointmentHistory(Appointment $appointment): JsonResponse
    {
        $items = SalePriceCorrection::query()
            ->with('correctedBy:id,name')
            ->where('subject_type', SalePriceCorrection::SUBJECT_APPOINTMENT)
            ->where('subject_id', $appointment->id)
            ->latest('id')
            ->get()
            ->map(fn (SalePriceCorrection $item) => $this->present($item))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function packageHistory(ServicePackage $package): JsonResponse
    {
        $items = SalePriceCorrection::query()
            ->with('correctedBy:id,name')
            ->where('subject_type', SalePriceCorrection::SUBJECT_PACKAGE)
            ->where('subject_id', $package->id)
            ->latest('id')
            ->get()
            ->map(fn (SalePriceCorrection $item) => $this->present($item))
            ->values();

        return response()->json(['data' => $items]);
    }

    /** @return array<string, mixed> */
    private function present(SalePriceCorrection $correction): array
    {
        return [
            'id' => $correction->id,
            'subject_type' => $correction->subject_type,
            'subject_id' => $correction->subject_id,
            'reason' => $correction->reason,
            'before_terms' => $correction->before_terms,
            'after_terms' => $correction->after_terms,
            'amount_paid_at_correction' => (float) $correction->amount_paid_at_correction,
            'currency' => $correction->currency,
            'corrected_by' => $correction->correctedBy ? [
                'id' => $correction->correctedBy->id,
                'name' => $correction->correctedBy->name,
            ] : null,
            'created_at' => optional($correction->created_at)?->toDateTimeString(),
        ];
    }
}
