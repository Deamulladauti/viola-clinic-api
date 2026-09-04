<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // Manual sale discount for a newly sold single treatment. Existing
            // package sessions do not accept a new discount here.
            'price' => ['nullable', 'numeric', 'min:0'],
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
            'package.sale_discount_type' => ['nullable', Rule::in(['fixed', 'percent']), 'required_with:package.sale_discount_value'],
            'package.sale_discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:package.sale_discount_type'],
            'package.currency' => ['nullable', Rule::in(['EUR', 'MKD'])],
            'package.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'package.notes' => ['nullable', 'string', 'max:5000'],
        ];
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
