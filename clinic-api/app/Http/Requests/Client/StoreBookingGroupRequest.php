<?php
namespace App\Http\Requests\Client;
use Illuminate\Foundation\Http\FormRequest;
class StoreBookingGroupRequest extends FormRequest {
 public function authorize(): bool { return $this->user() !== null; }
 public function rules(): array { return [
 'date'=>['required','date_format:Y-m-d'],'starts_at'=>['required','date_format:H:i'],'staff_id'=>['required','integer','exists:staff,id'],
 'treatments'=>['required','array','min:2','max:6'],'treatments.*.service_id'=>['required','integer','distinct','exists:services,id'],
 'treatments.*.service_package_id'=>['nullable','integer','exists:service_packages,id'],
 ]; }
}
