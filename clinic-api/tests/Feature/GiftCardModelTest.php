<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GiftCardModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_card_has_unique_code_and_independent_stored_balance(): void
    {
        $first = GiftCard::create([
            'initial_value' => '100.00',
            'remaining_value' => '100.00',
            'currency' => 'EUR',
        ]);
        $second = GiftCard::create([
            'initial_value' => '390.00',
            'remaining_value' => '390.00',
        ]);

        $this->assertMatchesRegularExpression('/^VC-[A-F0-9]{16}$/', $first->code);
        $this->assertNotSame($first->code, $second->code);
        $this->assertSame('100.00', $first->fresh()->remaining_value);
        $this->assertSame('active', $first->status);
        $this->assertNotNull($first->issued_at);
        $this->assertSame(2, DB::table('gift_cards')->count());
    }
}
