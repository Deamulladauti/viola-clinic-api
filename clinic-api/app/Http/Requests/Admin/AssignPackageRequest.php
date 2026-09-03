<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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

            // optional — override price or leave null to use service->price
            'price_total' => ['nullable', 'numeric', 'min:0'],
            'currency'    => ['nullable','string','size:3'],

            // optional package start date (packages do not expire)
            'starts_on'   => ['nullable','date'],

            // optional note
            'notes'       => ['nullable','string','max:2000'],
        ];
    }
}

