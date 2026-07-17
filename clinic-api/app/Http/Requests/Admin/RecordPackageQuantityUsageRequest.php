<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPackageQuantityUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('occurred_on') && !$this->filled('date')) {
            $this->merge(['occurred_on' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['minute', 'minutes', 'session', 'sessions'])],
            'minutes' => ['nullable', 'integer', 'min:1', 'required_without:amount'],
            'amount' => ['nullable', 'integer', 'min:1', 'required_without:minutes'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'required_without:date'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'required_without:occurred_on'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', Rule::in(['manual', 'imported'])],
        ];
    }

    public function minutes(): int
    {
        return (int) ($this->validated('minutes') ?? $this->validated('amount'));
    }

    public function occurredOn(): string
    {
        return (string) ($this->validated('occurred_on') ?? $this->validated('date'));
    }
}
