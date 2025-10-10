<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('service'); // {service} route model binding

        return [
            'service_category_id' => ['sometimes','integer','exists:service_categories,id'],
            'name'                => ['sometimes','string','max:255'],
            'slug'                => ['nullable','string','max:255', Rule::unique('services','slug')->ignore($id)],
            'description'         => ['nullable','string'],
            'duration_minutes'    => ['sometimes','integer','min:1','max:1440'],
            'price'               => ['sometimes','numeric','min:0'],
            'is_active'           => ['sometimes','boolean'],
            'tags'                => ['sometimes','array'],
            'tags.*'              => ['string','max:50'],
            'image' => ['nullable','image','max:4096'],
        ];
    }
}
