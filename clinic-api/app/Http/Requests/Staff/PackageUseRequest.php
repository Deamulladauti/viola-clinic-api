<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class PackageUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy handled in controller later
    }

    public function rules(): array
    {
        return [
            'used_sessions' => ['nullable', 'integer', 'min:0'],
            'used_minutes'  => ['nullable', 'integer', 'min:0'],
            'note'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $sessions = (int)$this->input('used_sessions', 0);
            $minutes  = (int)$this->input('used_minutes', 0);

            if (($sessions <= 0 && $minutes <= 0) || ($sessions > 0 && $minutes > 0)) {
                $v->errors()->add('used_sessions', 'Specify either used_sessions OR used_minutes (one, not both).');
            }
        });
    }
}
