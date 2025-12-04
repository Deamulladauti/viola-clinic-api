<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: replace with real gate (e.g. $this->user()->can('update', $this->appointment))
        return true;
    }

    public function rules(): array
    {
        return [
            // allow changing staff/date/time only in admin panel
            'service_id'       => ['sometimes', 'integer', 'exists:services,id'],
            'staff_id'         => ['sometimes', 'nullable', 'integer', 'exists:staff,id'],
            'date'             => ['sometimes', 'date_format:Y-m-d'],
            'starts_at'        => ['sometimes', 'date_format:H:i:s'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'price'            => ['sometimes', 'numeric', 'min:0', 'max:9999.99'],

            'status'           => ['sometimes', 'in:pending,confirmed,cancelled,completed,no_show'],
            'notes'            => ['sometimes', 'nullable', 'string', 'max:10000'],

            'customer_name'    => ['sometimes', 'string', 'max:255'],
            'customer_phone'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_email'   => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
