<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Works whether the route is {service} (model bound) or {id}
        $current = $this->route('service');
        $id = is_object($current) ? ($current->id ?? null) : ($current ?? $this->route('id'));

        return [
            // Core
            'service_category_id'   => ['sometimes','integer','exists:service_categories,id'],
            'name'                  => ['sometimes','string','max:255'],
            'slug'                  => ['sometimes','nullable','string','max:255', Rule::unique('services','slug')->ignore($id)],
            'short_description'     => ['sometimes','nullable','string'],
            'description'           => ['sometimes','nullable','string'],
            'price'                 => ['sometimes','numeric','min:0'],
            'duration_minutes'      => ['sometimes','integer','min:0','max:1440'],
            'is_active'             => ['sometimes','boolean'],
            'is_bookable'           => ['sometimes','boolean'],
            'is_package'            => ['sometimes','boolean'],
            'usage_type'            => ['sometimes','in:single,session,minutes'],
            'total_sessions'        => ['sometimes','nullable','integer','min:1'],
            'total_minutes'         => ['sometimes','nullable','integer','min:1'],
            'minimum_interval_days' => ['sometimes','integer','min:0','max:365'],
            'deduction_method'      => ['sometimes','in:automatic_on_completion,manual'],
            'staff_policy'          => ['sometimes','in:per_appointment,same_staff,any_qualified_staff'],

            // i18n
            'name_i18n'                    => ['sometimes','nullable','array'],
            'name_i18n.en'                 => ['nullable','string','max:255'],
            'name_i18n.sq'                 => ['nullable','string','max:255'],
            'name_i18n.mk'                 => ['nullable','string','max:255'],

            'short_description_i18n'       => ['sometimes','nullable','array'],
            'short_description_i18n.en'    => ['nullable','string'],
            'short_description_i18n.sq'    => ['nullable','string'],
            'short_description_i18n.mk'    => ['nullable','string'],

            'description_i18n'             => ['sometimes','nullable','array'],
            'description_i18n.en'          => ['nullable','string'],
            'description_i18n.sq'          => ['nullable','string'],
            'description_i18n.mk'          => ['nullable','string'],

            'prep_instructions'            => ['sometimes','nullable','array'],
            'prep_instructions.en'         => ['nullable','string'],
            'prep_instructions.sq'         => ['nullable','string'],
            'prep_instructions.mk'         => ['nullable','string'],

            // Relations
            'tag_ids'               => ['sometimes','array'],
            'tag_ids.*'             => ['integer','exists:tags,id'],
            'staff_ids'             => ['sometimes','array'],
            'staff_ids.*'           => ['integer','exists:staff,id'],

            'tags'                  => ['sometimes','array'],
            'tags.*'                => ['string','max:50'],

            // Media
            'image'                 => ['sometimes','nullable','image','max:4096'],
        ];
    }
}
