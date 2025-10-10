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

    protected function prepareForValidation(): void
    {
        $name = is_string($this->name) ? trim($this->name) : $this->name;

        // if slug missing, regenerate from (new) name; else trim provided slug
        $slug = $this->has('slug') && $this->slug !== null
            ? (is_string($this->slug) ? trim($this->slug) : $this->slug)
            : ($name ? Str::slug((string) $name) : $this->slug);

        $this->merge([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    public function rules(): array
    {
        // Route key could be 'category' or 'service_category' depending on your route.
        // If using implicit binding with {category}, this will resolve correctly:
        $id = $this->route('category')?->id ?? $this->route('category');

        return [
            'name'        => ['required','string','max:120', Rule::unique('service_categories','name')->ignore($id)],
            'slug'        => ['required','string','max:120', Rule::unique('service_categories','slug')->ignore($id)],
            'description' => ['nullable','string','max:10000'],
            'is_active'   => ['sometimes','boolean'],
            'image' => ['nullable','image','max:3072'],
        ];
    }
}
