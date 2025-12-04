<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class GuestBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    public function rules(): array
    {
        return [
            'date'        => ['required', 'date_format:Y-m-d'],
            'starts_at'   => ['required', 'date_format:H:i:s'],
            'staff_id'   => ['sometimes','integer','exists:staff,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            // no status from guests; defaults to pending
        ];
    }
}
