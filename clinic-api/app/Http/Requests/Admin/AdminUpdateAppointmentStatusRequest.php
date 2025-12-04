<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // add policy if needed
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,confirmed,cancelled,completed,no_show'],
            'notes'  => ['nullable', 'string', 'max:10000'],
        ];
    }
}
