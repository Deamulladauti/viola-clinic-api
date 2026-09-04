<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Service;
use App\Services\OfferPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Offer::query()
            ->with(['services:id,name,price,is_package,usage_type,total_sessions,total_minutes,is_active'])
            ->latest('id');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (Offer $offer) => $this->serializeOffer($offer))->values(),
        ]);
    }

    public function eligible(Request $request, OfferPricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ]);

        $service = Service::query()->findOrFail((int) $data['service_id']);

        $offers = Offer::query()
            ->currentlyActive()
            ->whereHas('services', fn ($query) => $query->where('services.id', $service->id))
            ->with(['services:id,name,price,is_package,usage_type,total_sessions,total_minutes,is_active'])
            ->orderBy('name')
            ->get();

        $items = $offers->map(function (Offer $offer) use ($pricing, $service) {
            $terms = $pricing->resolve($offer, $service);

            return array_merge($this->serializeOffer($offer), [
                'regular_price' => $terms['original_price'],
                'discount_type' => $terms['discount_type'],
                'discount_value' => $terms['discount_value'],
                'discount_amount' => $terms['discount_amount'],
                'final_price' => $terms['final_price'],
            ]);
        })->values();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, OfferPricingService $pricing): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $offer = DB::transaction(function () use ($request, $data, $pricing) {
            $offer = Offer::query()->create([
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
                'pricing_type' => $data['pricing_type'],
                'value' => $data['value'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $serviceIds = array_map('intval', $data['service_ids']);
            $this->validateAgainstServices($offer, $serviceIds, $pricing);
            $offer->services()->sync($serviceIds);

            return $offer->load('services:id,name,price,is_package,usage_type,total_sessions,total_minutes,is_active');
        });

        return response()->json(['data' => $this->serializeOffer($offer)], 201);
    }

    public function update(Request $request, Offer $offer, OfferPricingService $pricing): JsonResponse
    {
        $data = $this->validatedPayload($request);

        $offer = DB::transaction(function () use ($request, $offer, $data, $pricing) {
            $offer->fill([
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
                'pricing_type' => $data['pricing_type'],
                'value' => $data['value'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $request->user()?->id,
            ]);

            $serviceIds = array_map('intval', $data['service_ids']);
            $this->validateAgainstServices($offer, $serviceIds, $pricing);
            $offer->save();
            $offer->services()->sync($serviceIds);

            return $offer->load('services:id,name,price,is_package,usage_type,total_sessions,total_minutes,is_active');
        });

        return response()->json(['data' => $this->serializeOffer($offer)]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'pricing_type' => ['required', Rule::in(Offer::pricingTypes())],
            'value' => ['required', 'numeric', 'gt:0'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
        ]);
    }

    private function validateAgainstServices(Offer $offer, array $serviceIds, OfferPricingService $pricing): void
    {
        $services = Service::query()->whereIn('id', $serviceIds)->get();

        if ($services->count() !== count(array_unique($serviceIds))) {
            throw ValidationException::withMessages([
                'service_ids' => 'One or more selected services could not be found.',
            ]);
        }

        foreach ($services as $service) {
            try {
                $pricing->calculate($offer, $service);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first()
                    ?? 'The offer is not valid for this service.';

                throw ValidationException::withMessages([
                    'value' => "{$service->name}: {$message}",
                ]);
            }
        }
    }

    private function serializeOffer(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'description' => $offer->description,
            'pricing_type' => $offer->pricing_type,
            'value' => (float) $offer->value,
            'starts_on' => $offer->starts_on?->toDateString(),
            'ends_on' => $offer->ends_on?->toDateString(),
            'is_active' => (bool) $offer->is_active,
            'is_currently_active' => $offer->isCurrentlyActive(),
            'services' => $offer->services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'price' => (float) ($service->price ?? 0),
                'is_package' => (bool) $service->is_package,
                'usage_type' => $service->usage_type,
                'total_sessions' => $service->total_sessions,
                'total_minutes' => $service->total_minutes,
                'is_active' => (bool) $service->is_active,
            ])->values(),
            'created_at' => $offer->created_at?->toISOString(),
            'updated_at' => $offer->updated_at?->toISOString(),
        ];
    }
}
