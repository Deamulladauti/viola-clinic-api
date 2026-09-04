<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OfferPricingService
{
    public function __construct(
        private readonly ManualSalePricingService $manualPricing,
    ) {
    }

    /**
     * @return array{
     *   original_price: float,
     *   discount_type: ?string,
     *   discount_value: ?float,
     *   discount_amount: float,
     *   final_price: float,
     *   offer_id: int,
     *   offer_name: string,
     *   offer_pricing_type: string,
     *   offer_value: float
     * }
     */
    public function resolve(Offer $offer, Service $service, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today(config('clinic.timezone', config('app.timezone')));

        if (! $offer->isCurrentlyActive($asOf)) {
            throw ValidationException::withMessages([
                'offer_id' => 'The selected offer is not currently active.',
            ]);
        }

        $eligible = $offer->relationLoaded('services')
            ? $offer->services->contains(fn (Service $item) => (int) $item->id === (int) $service->id)
            : $offer->services()->whereKey($service->id)->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'offer_id' => 'The selected offer does not apply to this service.',
            ]);
        }

        return $this->calculate($offer, $service);
    }

    /**
     * Calculate terms without checking active dates/service membership.
     * Used while validating an Admin offer definition.
     */
    public function calculate(Offer $offer, Service $service): array
    {
        $originalPrice = round(max((float) ($service->price ?? 0), 0), 2);
        $value = round((float) $offer->value, 2);

        if ($value <= 0) {
            throw ValidationException::withMessages([
                'value' => 'Offer value must be greater than 0.',
            ]);
        }

        $saleTerms = match ($offer->pricing_type) {
            Offer::TYPE_PERCENT => $this->manualPricing->calculate(
                originalPrice: $originalPrice,
                discountType: 'percent',
                discountValue: $value,
            ),
            Offer::TYPE_FIXED_DISCOUNT => $this->manualPricing->calculate(
                originalPrice: $originalPrice,
                discountType: 'fixed',
                discountValue: $value,
            ),
            Offer::TYPE_FIXED_PRICE => $this->fixedPriceTerms($originalPrice, $value),
            default => throw ValidationException::withMessages([
                'pricing_type' => 'Unsupported offer pricing type.',
            ]),
        };

        if ($saleTerms['final_price'] >= $originalPrice && $originalPrice > 0) {
            throw ValidationException::withMessages([
                'value' => 'The offer must reduce the regular service price.',
            ]);
        }

        return array_merge($saleTerms, [
            'offer_id' => (int) $offer->id,
            'offer_name' => (string) $offer->name,
            'offer_pricing_type' => (string) $offer->pricing_type,
            'offer_value' => $value,
        ]);
    }

    private function fixedPriceTerms(float $originalPrice, float $offerPrice): array
    {
        if ($offerPrice < 0 || $offerPrice > $originalPrice) {
            throw ValidationException::withMessages([
                'value' => 'Offer price cannot be greater than the regular service price.',
            ]);
        }

        return $this->manualPricing->calculate(
            originalPrice: $originalPrice,
            discountType: 'fixed',
            discountValue: round($originalPrice - $offerPrice, 2),
        );
    }
}
