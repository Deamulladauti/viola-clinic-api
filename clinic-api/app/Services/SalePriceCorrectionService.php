<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\SalePriceCorrection;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePriceCorrectionService
{
    public function correctAppointment(
        Appointment $appointment,
        float $correctedFinalPrice,
        string $reason,
        User $admin,
    ): SalePriceCorrection {
        if ($appointment->service_package_id) {
            throw ValidationException::withMessages([
                'final_price' => 'This appointment belongs to a package. Correct the package sale price instead.',
            ]);
        }

        return DB::transaction(function () use ($appointment, $correctedFinalPrice, $reason, $admin) {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $currency = 'EUR';
            $amountPaid = $this->appointmentAmountPaidEur($locked);
            $before = $this->appointmentTerms($locked);
            $after = $this->buildCorrectedTerms($before, $correctedFinalPrice, $amountPaid);

            $locked->forceFill([
                'sale_original_price' => $after['original_price'],
                'sale_discount_type' => $after['discount_type'],
                'sale_discount_value' => $after['discount_value'],
                'sale_discount_amount' => $after['discount_amount'],
                'sale_final_price' => $after['final_price'],
                'sale_offer_id' => null,
                'sale_offer_name' => null,
                // Keep legacy consumers aligned with the corrected obligation.
                'price' => $after['final_price'],
            ])->save();

            return SalePriceCorrection::query()->create([
                'subject_type' => SalePriceCorrection::SUBJECT_APPOINTMENT,
                'subject_id' => $locked->id,
                'client_id' => $locked->user_id,
                'corrected_by' => $admin->id,
                'reason' => $reason,
                'before_terms' => $before,
                'after_terms' => $this->appointmentTerms($locked->fresh()),
                'amount_paid_at_correction' => round($amountPaid, 2),
                'currency' => $currency,
            ]);
        });
    }

    public function correctPackage(
        ServicePackage $package,
        float $correctedFinalPrice,
        string $reason,
        User $admin,
    ): SalePriceCorrection {
        return DB::transaction(function () use ($package, $correctedFinalPrice, $reason, $admin) {
            /** @var ServicePackage $locked */
            $locked = ServicePackage::query()->lockForUpdate()->findOrFail($package->id);
            $currency = $locked->packageCurrency();
            $amountPaid = (float) $locked->amount_paid;
            $before = $this->packageTerms($locked);
            $after = $this->buildCorrectedTerms($before, $correctedFinalPrice, $amountPaid);

            $locked->forceFill([
                'sale_original_price' => $after['original_price'],
                'sale_discount_type' => $after['discount_type'],
                'sale_discount_value' => $after['discount_value'],
                'sale_discount_amount' => $after['discount_amount'],
                'sale_final_price' => $after['final_price'],
                'sale_offer_id' => null,
                'sale_offer_name' => null,
                // Keep legacy package APIs aligned with the corrected obligation.
                'price_total' => $after['final_price'],
            ])->save();

            return SalePriceCorrection::query()->create([
                'subject_type' => SalePriceCorrection::SUBJECT_PACKAGE,
                'subject_id' => $locked->id,
                'client_id' => $locked->user_id,
                'corrected_by' => $admin->id,
                'reason' => $reason,
                'before_terms' => $before,
                'after_terms' => $this->packageTerms($locked->fresh()),
                'amount_paid_at_correction' => round($amountPaid, 2),
                'currency' => $currency,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function buildCorrectedTerms(array $before, float $correctedFinalPrice, float $amountPaid): array
    {
        $currentFinal = round((float) ($before['final_price'] ?? 0), 2);
        $original = round((float) ($before['original_price'] ?? $currentFinal), 2);
        $correctedFinalPrice = round($correctedFinalPrice, 2);
        $amountPaid = round($amountPaid, 2);

        if ($correctedFinalPrice < 0) {
            throw ValidationException::withMessages([
                'final_price' => 'Corrected final price cannot be negative.',
            ]);
        }

        if ($correctedFinalPrice >= $currentFinal - 0.001) {
            throw ValidationException::withMessages([
                'final_price' => 'Historical correction must lower the currently stored final price.',
            ]);
        }

        if ($correctedFinalPrice > $original + 0.001) {
            throw ValidationException::withMessages([
                'final_price' => 'Corrected final price cannot exceed the original sale price.',
            ]);
        }

        if ($correctedFinalPrice + 0.01 < $amountPaid) {
            throw ValidationException::withMessages([
                'final_price' => 'Corrected final price cannot be lower than the valid payments already recorded. Handle any overpayment separately.',
            ]);
        }

        $discountAmount = round(max($original - $correctedFinalPrice, 0), 2);

        return [
            'original_price' => $original,
            'discount_type' => $discountAmount > 0 ? 'fixed' : null,
            'discount_value' => $discountAmount > 0 ? $discountAmount : null,
            'discount_amount' => $discountAmount,
            'final_price' => $correctedFinalPrice,
            // A historical correction is intentionally not represented as an Offer.
            'offer_id' => null,
            'offer_name' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function appointmentTerms(Appointment $appointment): array
    {
        $final = round($appointment->finalSalePrice(), 2);
        $original = round((float) ($appointment->sale_original_price ?? $final), 2);

        return [
            'original_price' => $original,
            'discount_type' => $appointment->sale_discount_type,
            'discount_value' => $appointment->sale_discount_value !== null ? round((float) $appointment->sale_discount_value, 2) : null,
            'discount_amount' => round((float) ($appointment->sale_discount_amount ?? max($original - $final, 0)), 2),
            'final_price' => $final,
            'offer_id' => $appointment->sale_offer_id,
            'offer_name' => $appointment->sale_offer_name,
        ];
    }

    /** @return array<string, mixed> */
    private function packageTerms(ServicePackage $package): array
    {
        $final = round($package->finalSalePrice(), 2);
        $original = round((float) ($package->sale_original_price ?? $final), 2);

        return [
            'original_price' => $original,
            'discount_type' => $package->sale_discount_type,
            'discount_value' => $package->sale_discount_value !== null ? round((float) $package->sale_discount_value, 2) : null,
            'discount_amount' => round((float) ($package->sale_discount_amount ?? max($original - $final, 0)), 2),
            'final_price' => $final,
            'offer_id' => $package->sale_offer_id,
            'offer_name' => $package->sale_offer_name,
        ];
    }

    private function appointmentAmountPaidEur(Appointment $appointment): float
    {
        $paidMkd = $appointment->payments()
            ->notVoided()
            ->get()
            ->sum(function (PackagePayment $payment) {
                if ($payment->amount_mkd !== null) {
                    return (float) $payment->amount_mkd;
                }

                $currency = strtoupper((string) ($payment->currency ?: 'EUR'));
                $amount = (float) $payment->amount;

                if ($currency === 'EUR') {
                    $rate = (float) ($payment->exchange_rate ?: ServicePackage::EUR_TO_MKD);
                    return $amount * $rate;
                }

                return $amount;
            });

        return round($paidMkd / ServicePackage::EUR_TO_MKD, 2);
    }
}
