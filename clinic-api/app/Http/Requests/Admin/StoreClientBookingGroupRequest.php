<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientBookingGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'status' => [
                'nullable',
                Rule::in(['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'no-show']),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],

            'treatments' => ['required', 'array', 'min:2', 'max:10'],
            'treatments.*.purchase_type' => [
                'required',
                Rule::in(['single', 'existing_package', 'new_package']),
            ],
            'treatments.*.service_id' => [
                'required',
                'integer',
                'distinct',
                'exists:services,id',
            ],
            'treatments.*.service_package_id' => [
                'nullable',
                'integer',
                'exists:service_packages,id',
            ],
            'treatments.*.price' => ['nullable', 'numeric', 'min:0'],
            'treatments.*.offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'treatments.*.sale_discount_type' => [
                'nullable',
                Rule::in(['fixed', 'percent']),
            ],
            'treatments.*.sale_discount_value' => ['nullable', 'numeric', 'min:0'],
            'treatments.*.interval_override' => ['sometimes', 'boolean'],
            'treatments.*.interval_override_reason' => ['nullable', 'string', 'max:1000'],
            'treatments.*.staff_override' => ['sometimes', 'boolean'],
            'treatments.*.staff_override_reason' => ['nullable', 'string', 'max:1000'],
            'treatments.*.notes' => ['nullable', 'string', 'max:10000'],

            'treatments.*.package' => ['nullable', 'array'],
            'treatments.*.package.price_total' => ['nullable', 'numeric', 'min:0'],
            'treatments.*.package.offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'treatments.*.package.sale_discount_type' => [
                'nullable',
                Rule::in(['fixed', 'percent']),
            ],
            'treatments.*.package.sale_discount_value' => ['nullable', 'numeric', 'min:0'],
            'treatments.*.package.currency' => ['nullable', Rule::in(['EUR', 'MKD'])],
            'treatments.*.package.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'treatments.*.package.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('treatments', []) as $index => $treatment) {
                $treatment = (array) $treatment;
                $purchaseType = (string) ($treatment['purchase_type'] ?? '');
                $servicePackageId = $treatment['service_package_id'] ?? null;
                $singleOffer = $treatment['offer_id'] ?? null;
                $singleDiscount = array_key_exists('sale_discount_type', $treatment)
                    || array_key_exists('sale_discount_value', $treatment);

                $package = (array) ($treatment['package'] ?? []);
                $packageOffer = $package['offer_id'] ?? null;
                $packageDiscount = array_key_exists('sale_discount_type', $package)
                    || array_key_exists('sale_discount_value', $package);

                if ($purchaseType === 'existing_package' && !$servicePackageId) {
                    $validator->errors()->add(
                        "treatments.{$index}.service_package_id",
                        'Choose the exact package for this treatment.'
                    );
                }

                if ($purchaseType !== 'existing_package' && $servicePackageId) {
                    $validator->errors()->add(
                        "treatments.{$index}.service_package_id",
                        'A package id is only valid when using an existing package.'
                    );
                }

                if ($singleOffer && $singleDiscount) {
                    $validator->errors()->add(
                        "treatments.{$index}.offer_id",
                        'Choose either an offer or a manual discount for this single treatment, not both.'
                    );
                }

                if ($packageOffer && $packageDiscount) {
                    $validator->errors()->add(
                        "treatments.{$index}.package.offer_id",
                        'Choose either an offer or a manual discount for this new package, not both.'
                    );
                }

                if ($purchaseType !== 'single' && ($singleOffer || $singleDiscount)) {
                    $validator->errors()->add(
                        "treatments.{$index}.offer_id",
                        'Single-treatment sale pricing is only valid for a single treatment purchase.'
                    );
                }

                if ($purchaseType !== 'new_package' && ($packageOffer || $packageDiscount || $package)) {
                    // An empty array is harmless, but real package-sale fields must only
                    // accompany a new package purchase.
                    $hasPackageFields = collect($package)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
                    if ($hasPackageFields) {
                        $validator->errors()->add(
                            "treatments.{$index}.package",
                            'Package sale terms are only valid when purchasing a new package.'
                        );
                    }
                }

                if (($treatment['interval_override'] ?? false) && blank($treatment['interval_override_reason'] ?? null)) {
                    $validator->errors()->add(
                        "treatments.{$index}.interval_override_reason",
                        'A reason is required for an interval override.'
                    );
                }

                if (($treatment['staff_override'] ?? false) && blank($treatment['staff_override_reason'] ?? null)) {
                    $validator->errors()->add(
                        "treatments.{$index}.staff_override_reason",
                        'A reason is required for a staff override.'
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('status') === 'no-show') {
            $this->merge(['status' => 'no_show']);
        }

        $treatments = collect((array) $this->input('treatments', []))
            ->map(function ($treatment) {
                $treatment = (array) $treatment;
                if (isset($treatment['package']) && is_array($treatment['package'])) {
                    $package = $treatment['package'];
                    if (isset($package['currency'])) {
                        $package['currency'] = strtoupper((string) $package['currency']);
                    }
                    $treatment['package'] = $package;
                }

                return $treatment;
            })
            ->all();

        if ($treatments) {
            $this->merge(['treatments' => $treatments]);
        }
    }
}
