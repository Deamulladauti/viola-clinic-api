<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServiceStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; } // TODO: add policy later

    public function rules(): array
    {
        return [
            'service_category_id' => ['required','integer','exists:service_categories,id'],
            'name'                => ['required','string','max:255'],
            'slug'                => ['nullable','string','max:255','unique:services,slug'],
            'description'         => ['nullable','string'],
            'duration_minutes'    => ['required','integer','min:1','max:1440'],
            'price'               => ['required','numeric','min:0'],
            'is_active'           => ['sometimes','boolean'],
            'tags'                => ['sometimes','array'],
            'tags.*'              => ['string','max:50'],
            'image' => ['nullable','image','max:4096'],
        ];
    }
}
