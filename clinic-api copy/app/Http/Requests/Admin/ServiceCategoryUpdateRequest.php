<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize only the fields that are actually present,
     * so we can do partial updates (e.g. image-only).
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        // Only normalize name if it was sent
        if ($this->has('name')) {
            $name = is_string($this->name) ? trim($this->name) : $this->name;
            $merged['name'] = $name;

            // If slug NOT sent but name is, auto-generate slug from name
            if (!$this->has('slug') && $name) {
                $merged['slug'] = Str::slug((string) $name);
            }
        }

        // If slug was sent explicitly, normalize it
        if ($this->has('slug')) {
            $slug = is_string($this->slug) ? trim($this->slug) : $this->slug;
            $merged['slug'] = $slug;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        // Route key could be 'category' depending on your route model binding
        $id = $this->route('category')?->id ?? $this->route('category');

        return [
            // name/slug become optional (only validated if present),
            // but required IF they are present.
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('service_categories', 'name')->ignore($id),
            ],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('service_categories', 'slug')->ignore($id),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:10000',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'image' => [
                'sometimes',
                'image',
                'max:3072', // ~3MB
            ],
        ];
    }
}
