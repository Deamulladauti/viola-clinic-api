<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\PackagePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GiftCardPaymentVoidService
{
    public function void(PackagePayment $payment, int $actorId, string $reason): PackagePayment
    {
        return DB::transaction(function () use ($payment, $actorId, $reason) {
            $redemption = DB::table('gift_card_redemptions')->where('package_payment_id', $payment->id)->first();
            // Always acquire card lock before payment lock, matching redemption ordering.
            $card = $redemption ? GiftCard::query()->lockForUpdate()->findOrFail($redemption->gift_card_id) : null;
            $locked = PackagePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->voided_at || ($redemption && $redemption->voided_at)) {
                throw ValidationException::withMessages(['payment' => 'Payment is already voided.']);
            }
            $locked->forceFill(['voided_at' => now(), 'voided_by_id' => $actorId, 'void_reason' => $reason])->save();
            if ($redemption && $card) {
                $restored = (int) round((float) $card->remaining_value * 100)
                    + (int) round((float) $redemption->amount_eur * 100);
                if ($restored > (int) round((float) $card->initial_value * 100)) {
                    throw ValidationException::withMessages(['payment' => 'Gift card refund would exceed its original value.']);
                }
                $card->remaining_value = number_format($restored / 100, 2, '.', '');
                // Preserve an administrator's disabled status.
                if ($card->status !== GiftCard::STATUS_DISABLED) {
                    $card->status = $restored > 0 ? GiftCard::STATUS_ACTIVE : GiftCard::STATUS_EXHAUSTED;
                }
                $card->save();
                DB::table('gift_card_redemptions')->where('id', $redemption->id)->update([
                    'voided_at' => now(), 'voided_by_id' => $actorId,
                    'void_reason' => $reason, 'updated_at' => now(),
                ]);
            }
            return $locked;
        }, 3);
    }
}
