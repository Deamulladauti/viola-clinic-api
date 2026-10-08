<?php
namespace App\Http\Requests\Staff;

use App\Http\Requests\Admin\StoreClientAppointmentRequest;
use Illuminate\Validation\Rule;

class StoreStaffClientAppointmentRequest extends StoreClientAppointmentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'starts_at' => ['required', 'date_format:H:i'],
            'status' => ['required', Rule::in(['confirmed'])],
            'price' => ['prohibited'],
            'sale_discount_type' => ['prohibited'],
            'sale_discount_value' => ['prohibited'],
            'notes' => ['prohibited'],
            'interval_override' => ['prohibited'],
            'interval_override_reason' => ['prohibited'],
            'staff_override' => ['prohibited'],
            'staff_override_reason' => ['prohibited'],
            'package.price_total' => ['prohibited'],
            'package.sale_discount_type' => ['prohibited'],
            'package.sale_discount_value' => ['prohibited'],
            'package.starts_on' => ['prohibited'],
            'package.notes' => ['prohibited'],
        ]);
    }
}
