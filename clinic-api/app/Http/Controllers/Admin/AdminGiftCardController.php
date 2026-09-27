<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminGiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'exhausted', 'disabled'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = GiftCard::query()->with(['recipient:id,name', 'createdBy:id,name'])->latest('id');
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('code', 'like', "%{$q}%")
                    ->orWhere('recipient_name', 'like', "%{$q}%")
                    ->orWhere('recipient_email', 'like', "%{$q}%")
                    ->orWhere('recipient_phone', 'like', "%{$q}%");
            });
        }
        return response()->json($query->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'initial_value' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999'],
            'recipient_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'purchased_at' => ['nullable', 'date'],
        ]);
        $card = GiftCard::query()->create([
            ...$data,
            'remaining_value' => number_format((float) $data['initial_value'], 2, '.', ''),
            'currency' => 'EUR',
            'status' => GiftCard::STATUS_ACTIVE,
            'created_by_id' => $request->user()->id,
        ]);
        return response()->json(['data' => $card->load(['recipient:id,name', 'createdBy:id,name'])], 201);
    }

    public function show(GiftCard $giftCard): JsonResponse
    {
        return response()->json(['data' => $giftCard->load(['recipient:id,name', 'createdBy:id,name'])]);
    }

    public function disable(GiftCard $giftCard): JsonResponse
    {
        $card = DB::transaction(function () use ($giftCard) {
            $card = GiftCard::query()->lockForUpdate()->findOrFail($giftCard->id);
            if ($card->status === GiftCard::STATUS_ACTIVE) {
                $card->status = GiftCard::STATUS_DISABLED;
                $card->save();
            }
            return $card;
        });
        return response()->json(['data' => $card->load(['recipient:id,name', 'createdBy:id,name'])]);
    }

    public function enable(GiftCard $giftCard): JsonResponse
    {
        $card = DB::transaction(function () use ($giftCard) {
            $card = GiftCard::query()->lockForUpdate()->findOrFail($giftCard->id);
            if ($card->status === GiftCard::STATUS_DISABLED) {
                // Never reactivate an exhausted card, even if it was disabled earlier.
                $card->status = (float) $card->remaining_value > 0
                    ? GiftCard::STATUS_ACTIVE : GiftCard::STATUS_EXHAUSTED;
                $card->save();
            }
            return $card;
        });
        return response()->json(['data' => $card->load(['recipient:id,name', 'createdBy:id,name'])]);
    }
}
