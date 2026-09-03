<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Operational booking edits. The backend recalculates duration and
            // validates the exact service/package/staff/date/time combination.
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'service_package_id' => ['sometimes', 'nullable', 'integer', 'exists:service_packages,id'],
            'staff_id' => ['sometimes', 'integer', 'exists:staff,id'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'regex:/^\\d{2}:\\d{2}(?::\\d{2})?$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],

            // Package rule overrides are intentionally explicit and audited.
            'interval_override' => ['sometimes', 'boolean'],
            'interval_override_reason' => ['nullable', 'string', 'max:1000'],
            'staff_override' => ['sometimes', 'boolean'],
            'staff_override_reason' => ['nullable', 'string', 'max:1000'],

            // Kept for backwards compatibility with older admin helpers that
            // used PATCH /appointments/{id} for status/notes only.
            'status' => ['sometimes', 'in:pending,confirmed,cancelled,completed,no_show'],
        ];
    }
}
