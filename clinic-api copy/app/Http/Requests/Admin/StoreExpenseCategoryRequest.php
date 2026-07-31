<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('expense_categories', 'slug')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}