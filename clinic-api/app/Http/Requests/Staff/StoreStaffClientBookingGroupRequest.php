<?php
namespace App\Http\Requests\Staff;

use App\Http\Requests\Admin\StoreClientBookingGroupRequest;
use Illuminate\Validation\Rule;

class StoreStaffClientBookingGroupRequest extends StoreClientBookingGroupRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'starts_at' => ['required', 'date_format:H:i'],
            'status' => ['required', Rule::in(['confirmed'])],
            'notes' => ['prohibited'],
            'treatments.*.price' => ['prohibited'],
            'treatments.*.sale_discount_type' => ['prohibited'],
            'treatments.*.sale_discount_value' => ['prohibited'],
            'treatments.*.interval_override' => ['prohibited'],
            'treatments.*.interval_override_reason' => ['prohibited'],
            'treatments.*.staff_override' => ['prohibited'],
            'treatments.*.staff_override_reason' => ['prohibited'],
            'treatments.*.notes' => ['prohibited'],
            'treatments.*.package.price_total' => ['prohibited'],
            'treatments.*.package.sale_discount_type' => ['prohibited'],
            'treatments.*.package.sale_discount_value' => ['prohibited'],
            'treatments.*.package.starts_on' => ['prohibited'],
            'treatments.*.package.notes' => ['prohibited'],
        ]);
    }
}
