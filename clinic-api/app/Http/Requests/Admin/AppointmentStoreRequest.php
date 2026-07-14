<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: replace with real gate (e.g. $this->user()->can('create', Appointment::class))
        return true;
    }

    public function rules(): array
    {
        return [
            // --- Core references ---
            'service_id'         => ['required', 'integer', 'exists:services,id'],
            'service_package_id' => ['nullable', 'integer', 'exists:service_packages,id'],
            'staff_id'           => ['nullable', 'integer', 'exists:staff,id'],
            'user_id'            => ['nullable', 'integer', 'exists:users,id'],

            // --- Timing ---
            'date'             => ['required', 'date_format:Y-m-d'],
            'starts_at'        => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],

            // --- Pricing ---
            'price'            => ['required', 'numeric', 'min:0', 'max:9999.99'],

            // --- Customer data (used when admin books for a client) ---
            'customer_name'    => ['required', 'string', 'max:255'],
            'customer_phone'   => ['nullable', 'string', 'max:50'],
            'customer_email'   => ['nullable', 'email', 'max:255'],

            // --- Status & notes ---
            'status'           => ['required', 'in:pending,confirmed,cancelled,completed,no_show'],
            'source'           => ['sometimes', 'in:client_booking,admin_booking,manual_import,legacy'],
            'notes'            => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.exists' => 'The selected service does not exist.',
            'staff_id.exists'   => 'The selected staff member does not exist.',
        ];
    }
}
