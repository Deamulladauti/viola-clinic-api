<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreBookingGroupRequest;
use App\Models\Service;
use App\Services\AdminBookingGroupService;
use App\Services\BookingGroupAvailabilityService;
use App\Services\BookingGroupStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientBookingGroupController extends Controller
{
    public function staff(Request $request, BookingGroupStaffService $service): JsonResponse
    {
        $data = $request->validate([
            'service_ids' => ['required','array','min:2','max:6'],
            'service_ids.*' => ['required','integer','distinct','exists:services,id'],
            'package_ids' => ['nullable','array','max:6'],
            'package_ids.*' => ['integer','distinct','exists:service_packages,id'],
        ]);
        $this->assertClientBookable($data['service_ids']);
        return response()->json(['data' => $service->eligibleStaff(array_map('intval',$data['service_ids']), array_map('intval',$data['package_ids'] ?? []))]);
    }

    public function availability(Request $request, BookingGroupAvailabilityService $service): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required','date_format:Y-m-d'],
            'staff_id' => ['required','integer','exists:staff,id'],
            'service_ids' => ['required','array','min:2','max:6'],
            'service_ids.*' => ['required','integer','distinct','exists:services,id'],
            'booking_group_id' => ['nullable','integer','exists:booking_groups,id'],
        ]);
        $this->assertClientBookable($data['service_ids']);
        $ignoreIds = [];
        if (!empty($data['booking_group_id'])) {
            $email = strtolower(trim((string) $request->user()->email));
            $group = \App\Models\BookingGroup::query()
                ->whereKey((int) $data['booking_group_id'])
                ->whereHas('appointments', fn ($q) => $q->whereRaw('LOWER(customer_email) = ?', [$email]))
                ->with('appointments:id,booking_group_id')
                ->firstOrFail();
            $ignoreIds = $group->appointments->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        return response()->json(['data' => $service->availableSlots(array_map('intval',$data['service_ids']), (int)$data['staff_id'], (string)$data['date'], 15, $ignoreIds)]);
    }

    public function store(StoreBookingGroupRequest $request, AdminBookingGroupService $service): JsonResponse
    {
        $data = $request->validated();
        $this->assertClientBookable(array_column($data['treatments'], 'service_id'));

        $treatments = collect($data['treatments'])->map(function (array $treatment) use ($request) {
            $packageId = $treatment['service_package_id'] ?? null;
            if ($packageId) {
                $owned = $request->user()->servicePackages()->whereKey($packageId)->where('service_id', $treatment['service_id'])->exists();
                if (!$owned) throw ValidationException::withMessages(['treatments' => 'One of the selected packages is not available for this account.']);
            }
            $serviceModel = Service::query()->findOrFail((int) $treatment['service_id']);
            $offerId = null;
            if (!$packageId) {
                $best = null;
                foreach ($serviceModel->offers()->currentlyActive()->get() as $offer) {
                    try { $terms = app(\App\Services\OfferPricingService::class)->resolve($offer, $serviceModel); } catch (\Throwable) { continue; }
                    if ($best === null || (float) $terms['final_price'] < (float) $best['final_price']) { $best = ['id' => (int) $offer->id, 'final_price' => (float) $terms['final_price']]; }
                }
                $offerId = $best['id'] ?? null;
            }
            return [
                'service_id' => (int)$treatment['service_id'],
                'purchase_type' => $packageId ? 'existing_package' : 'single',
                'service_package_id' => $packageId ? (int)$packageId : null,
                'offer_id' => $offerId,
            ];
        })->all();

        $result = $service->create($request->user(), [
            'date' => $data['date'], 'starts_at' => $data['starts_at'], 'staff_id' => (int)$data['staff_id'],
            'status' => 'confirmed', 'treatments' => $treatments,
        ], $request->user());

        return response()->json(['message'=>'Joined booking created successfully.','data'=>[
            'booking_group_id'=>$result['group']->id,
            'appointment_ids'=>$result['group']->appointments->pluck('id')->values()->all(),
            'total_duration_minutes'=>(int)$result['total_duration_minutes'],
            'warnings'=>$result['warnings'],
        ]], 201);
    }

    private function assertClientBookable(array $ids): void
    {
        $count = Service::query()->whereIn('id',$ids)->where('is_active',true)->where('is_bookable',true)->count();
        if ($count !== count(array_unique(array_map('intval',$ids)))) {
            throw ValidationException::withMessages(['service_ids'=>'One or more selected treatments are not available for online booking.']);
        }
    }
}
