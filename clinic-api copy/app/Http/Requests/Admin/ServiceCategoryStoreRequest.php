<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceCategoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes are already behind auth:sanctum + role:admin middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = is_string($this->name) ? trim($this->name) : $this->name;
        $slug = $this->slug ?? Str::slug((string) $name);

        $this->merge([
            'name' => $name,
            'slug' => is_string($slug) ? trim($slug) : $slug,
        ]);
    }

    public function rules(): array
    {
        return [
            'name'        => ['required','string','max:120', Rule::unique('service_categories','name')],
            'slug'        => ['required','string','max:120', Rule::unique('service_categories','slug')],
            'description' => ['nullable','string','max:10000'],
            'is_active'   => ['sometimes','boolean'],
            'image' => ['nullable','image','max:3072'],
        ];
    }
}
