<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate via route middleware (role:admin) or policies
    }

    public function rules(): array
    {
        return [
            'user_id'     => ['required','integer','exists:users,id'],
            'service_id'  => ['required','integer','exists:services,id'],
            'assigned_staff_id' => ['nullable','integer','exists:staff,id'],

            // Task 10: either an eligible configured offer or a manual discount.
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'],

            // Legacy explicit final price remains accepted for compatibility.
            // New Admin flows should send offer_id or sale_discount_type/value.
            'price_total' => ['nullable', 'numeric', 'min:0'],
            'sale_discount_type' => ['nullable', Rule::in(['fixed', 'percent']), 'required_with:sale_discount_value'],
            'sale_discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:sale_discount_type'],
            'currency'    => ['nullable','string','size:3'],

            // optional package start date (packages do not expire)
            'starts_on'   => ['nullable','date'],

            // optional note
            'notes'       => ['nullable','string','max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasOffer = $this->filled('offer_id');
            $hasManualDiscount = $this->filled('sale_discount_type')
                || $this->filled('sale_discount_value');

            if ($hasOffer && $hasManualDiscount) {
                $validator->errors()->add(
                    'offer_id',
                    'Choose either an offer or a manual discount, not both.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }
}
