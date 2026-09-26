<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:100'],
            'phone'    => ['required', 'string', 'max:50'],
            'email'    => ['nullable', 'email', 'max:255'],
            'link_code' => ['nullable', 'digits:8'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
