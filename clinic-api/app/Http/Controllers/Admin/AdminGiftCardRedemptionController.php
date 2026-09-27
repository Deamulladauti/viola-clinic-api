<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\GiftCard;
use App\Models\PackagePayment;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminGiftCardRedemptionController extends Controller
{
    public function history(GiftCard $giftCard): JsonResponse
    {
        return response()->json(['data' => DB::table('gift_card_redemptions')
            ->where('gift_card_id', $giftCard->id)->orderByDesc('id')->get()]);
    }

    public function redeem(Request $request, GiftCard $giftCard): JsonResponse
    {
        $data = $request->validate([
            'amount_eur' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'service_package_id' => ['required_without:appointment_id', 'nullable', 'integer', 'exists:service_packages,id', 'prohibits:appointment_id'],
            'appointment_id' => ['required_without:service_package_id', 'nullable', 'integer', 'exists:appointments,id', 'prohibits:service_package_id'],
        ]);
        $cents = (int) round((float) $data['amount_eur'] * 100);
        $rate = ServicePackage::EUR_TO_MKD;
        $amountMkd = round($cents / 100 * $rate, 2);

        $result = DB::transaction(function () use ($giftCard, $data, $cents, $amountMkd, $rate, $request) {
            // Lock the gift card first for every redemption and reversal.
            $card = GiftCard::query()->lockForUpdate()->findOrFail($giftCard->id);
            $before = (int) round((float) $card->remaining_value * 100);
            if ($card->status !== GiftCard::STATUS_ACTIVE || $before < $cents) {
                throw ValidationException::withMessages(['amount_eur' => 'Gift card is inactive or has insufficient balance.']);
            }

            $packageId = $data['service_package_id'] ?? null;
            $appointmentId = $data['appointment_id'] ?? null;
            if ($packageId !== null) {
                $package = ServicePackage::query()->lockForUpdate()->findOrFail($packageId);
                $remainingMkd = (float) $package->remaining_to_pay_mkd;
                $clientId = $package->user_id;
            } else {
                $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
                if ($appointment->service_package_id) {
                    throw ValidationException::withMessages(['appointment_id' => 'Package sessions must be paid through the package.']);
                }
                $paidMkd = PackagePayment::query()->where('appointment_id', $appointment->id)
                    ->whereNull('voided_at')->get()->sum(function (PackagePayment $payment) use ($rate) {
                        return $payment->amount_mkd !== null ? (float) $payment->amount_mkd
                            : (float) $payment->amount * ($payment->currency === 'EUR' ? (float) ($payment->exchange_rate ?: $rate) : 1);
                    });
                $remainingMkd = max(0, round((float) $appointment->finalSalePrice() * $rate - $paidMkd, 2));
                $clientId = $appointment->user_id;
            }
            if ($amountMkd > round($remainingMkd, 2) || $remainingMkd <= 0) {
                throw ValidationException::withMessages(['amount_eur' => 'Redemption exceeds the remaining service balance.']);
            }
            $after = $before - $cents;
            $payment = PackagePayment::create([
                'service_package_id' => $packageId,
                'appointment_id' => $appointmentId,
                'user_id' => $clientId,
                'admin_id' => $request->user()->id,
                'method' => 'other',
                'amount' => number_format($cents / 100, 2, '.', ''),
                'currency' => 'EUR',
                'exchange_rate' => $rate,
                'amount_mkd' => $amountMkd,
                'notes' => 'Gift card ' . $card->code,
            ]);
            DB::table('gift_card_redemptions')->insert([
                'gift_card_id' => $card->id,
                'package_payment_id' => $payment->id,
                'created_by_id' => $request->user()->id,
                'amount_eur' => number_format($cents / 100, 2, '.', ''),
                'amount_mkd' => $amountMkd,
                'balance_before' => number_format($before / 100, 2, '.', ''),
                'balance_after' => number_format($after / 100, 2, '.', ''),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $card->remaining_value = number_format($after / 100, 2, '.', '');
            $card->status = $after === 0 ? GiftCard::STATUS_EXHAUSTED : GiftCard::STATUS_ACTIVE;
            $card->save();
            return ['card' => $card->fresh(), 'payment_id' => $payment->id];
        }, 3);

        return response()->json(['data' => $result], 201);
    }
}
