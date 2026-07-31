<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // this field can be phone OR email
            'identifier' => ['required', 'string', 'max:255'],
            'password'   => ['required', 'string', 'min:8'],
        ];
    }
}
