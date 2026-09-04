<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ManualSalePricingService
{
    /**
     * @return array{original_price: float, discount_type: ?string, discount_value: ?float, discount_amount: float, final_price: float}
     */
    public function calculate(float $originalPrice, ?string $discountType = null, mixed $discountValue = null): array
    {
        $originalPrice = round(max($originalPrice, 0), 2);
        $discountType = $discountType !== null && $discountType !== ''
            ? strtolower(trim($discountType))
            : null;

        if ($discountType === null) {
            return [
                'original_price' => $originalPrice,
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => 0.0,
                'final_price' => $originalPrice,
            ];
        }

        if (! in_array($discountType, ['fixed', 'percent'], true)) {
            throw ValidationException::withMessages([
                'sale_discount_type' => 'Discount type must be fixed or percent.',
            ]);
        }

        if ($discountValue === null || $discountValue === '') {
            throw ValidationException::withMessages([
                'sale_discount_value' => 'Enter a discount value.',
            ]);
        }

        $discountValue = round((float) $discountValue, 2);

        if ($discountValue < 0) {
            throw ValidationException::withMessages([
                'sale_discount_value' => 'Discount cannot be negative.',
            ]);
        }

        if ($discountType === 'percent') {
            if ($discountValue > 100) {
                throw ValidationException::withMessages([
                    'sale_discount_value' => 'Percentage discount cannot be greater than 100%.',
                ]);
            }

            $discountAmount = round($originalPrice * ($discountValue / 100), 2);
        } else {
            if ($discountValue > $originalPrice) {
                throw ValidationException::withMessages([
                    'sale_discount_value' => 'Fixed discount cannot be greater than the original price.',
                ]);
            }

            $discountAmount = $discountValue;
        }

        $finalPrice = round(max($originalPrice - $discountAmount, 0), 2);

        if ($discountAmount <= 0) {
            return [
                'original_price' => $originalPrice,
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => 0.0,
                'final_price' => $originalPrice,
            ];
        }

        return [
            'original_price' => $originalPrice,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
        ];
    }
}
