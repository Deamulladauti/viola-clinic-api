<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // Legacy explicit final price remains accepted for compatibility.
            // New Admin flows should send sale_discount_type/value instead.
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
}

