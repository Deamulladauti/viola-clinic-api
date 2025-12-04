<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServiceStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; } // TODO: policy

    public function rules(): array
    {
        return [
            // Core
            'service_category_id'   => ['required','integer','exists:service_categories,id'],
            'name'                  => ['required','string','max:255'],
            'slug'                  => ['nullable','string','max:255','unique:services,slug'],
            'short_description'     => ['nullable','string'],
            'description'           => ['nullable','string'],
            'price'                 => ['required','numeric','min:0'],
            'duration_minutes'      => ['required','integer','min:0','max:1440'], // 0 allowed
            'is_active'             => ['sometimes','boolean'],
            'is_bookable'           => ['sometimes','boolean'],

            // i18n JSON objects (keys optional: en/sq/mk)
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

            // Relations (either form is fine for your controller to map)
            'tag_ids'               => ['sometimes','array'],
            'tag_ids.*'             => ['integer','exists:tags,id'],
            'staff_ids'             => ['sometimes','array'],
            'staff_ids.*'           => ['integer','exists:staff,id'],

            // Legacy tags by name (optional; keep if you still use it)
            'tags'                  => ['sometimes','array'],
            'tags.*'                => ['string','max:50'],

            // Media
            'image'                 => ['nullable','image','max:4096'],
        ];
    }
}

