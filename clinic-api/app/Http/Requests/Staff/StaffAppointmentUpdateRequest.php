<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StaffAppointmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy handles object-level auth; route is already staff-protected
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending,confirmed,cancelled,completed,no_show'],
            'notes'  => ['nullable', 'string', 'max:10000'],
        ];
    }
}
