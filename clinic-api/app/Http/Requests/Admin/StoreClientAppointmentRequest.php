<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_type' => ['required', Rule::in(['single', 'existing_package', 'new_package'])],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_package_id' => [
                'nullable',
                'integer',
                'exists:service_packages,id',
                'required_if:purchase_type,existing_package',
            ],

            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'status' => [
                'nullable',
                Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'no-show']),
            ],

            // New single-treatment sale pricing.
            'price' => ['nullable', 'numeric', 'min:0'],
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'sale_discount_type' => ['nullable', Rule::in(['fixed', 'percent']), 'required_with:sale_discount_value'],
            'sale_discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:sale_discount_type'],
            'notes' => ['nullable', 'string', 'max:10000'],

            'interval_override' => ['sometimes', 'boolean'],
            'interval_override_reason' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:interval_override,true,1',
            ],

            'staff_override' => ['sometimes', 'boolean'],
            'staff_override_reason' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:staff_override,true,1',
            ],

            'package' => ['nullable', 'array'],
            'package.price_total' => ['nullable', 'numeric', 'min:0'],
            'package.offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'package.sale_discount_type' => ['nullable', Rule::in(['fixed', 'percent']), 'required_with:package.sale_discount_value'],
            'package.sale_discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:package.sale_discount_type'],
            'package.currency' => ['nullable', Rule::in(['EUR', 'MKD'])],
            'package.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'package.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $purchaseType = (string) $this->input('purchase_type');
            $singleOffer = $this->input('offer_id');
            $singleDiscount = $this->input('sale_discount_type') !== null
                || $this->input('sale_discount_value') !== null;

            $package = (array) $this->input('package', []);
            $packageOffer = $package['offer_id'] ?? null;
            $packageDiscount = array_key_exists('sale_discount_type', $package)
                || array_key_exists('sale_discount_value', $package);

            if ($singleOffer && $singleDiscount) {
                $validator->errors()->add(
                    'offer_id',
                    'Choose either an offer or a manual discount for the single treatment, not both.'
                );
            }

            if ($packageOffer && $packageDiscount) {
                $validator->errors()->add(
                    'package.offer_id',
                    'Choose either an offer or a manual discount for the package, not both.'
                );
            }

            if ($purchaseType !== 'single' && ($singleOffer || $singleDiscount)) {
                $validator->errors()->add(
                    'offer_id',
                    'Single-treatment sale pricing is only valid when purchase_type is single.'
                );
            }

            if ($purchaseType !== 'new_package' && ($packageOffer || $packageDiscount)) {
                $validator->errors()->add(
                    'package.offer_id',
                    'Package sale pricing is only valid when purchasing a new package.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('status') === 'no-show') {
            $this->merge(['status' => 'no_show']);
        }

        if ($this->has('package.currency')) {
            $package = (array) $this->input('package', []);
            $package['currency'] = strtoupper((string) ($package['currency'] ?? ''));
            $this->merge(['package' => $package]);
        }
    }
}
