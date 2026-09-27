<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task39GiftCardRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $client = User::create(['name' => 'Client', 'email' => 'client@example.com', 'password' => 'password']);
        $category = DB::table('service_categories')->insertGetId(['name' => 'Test', 'slug' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $service = DB::table('services')->insertGetId(['service_category_id' => $category, 'name' => 'Laser', 'slug' => 'laser', 'duration_minutes' => 30, 'price' => 390, 'created_at' => now(), 'updated_at' => now()]);
        $package = DB::table('service_packages')->insertGetId(['user_id' => $client->id, 'service_id' => $service, 'service_name' => 'Laser', 'price_total' => 390, 'sale_final_price' => 390, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);
        $card = GiftCard::create(['initial_value' => '100.00', 'remaining_value' => '100.00']);
        return [$card, $package];
    }

    public function test_partial_redemption_counts_as_package_payment_and_void_restores_balance(): void
    {
        [$card, $package] = $this->fixture();
        $response = $this->postJson("/api/v1/admin/gift-cards/{$card->id}/redeem", [
            'amount_eur' => '40.00', 'service_package_id' => $package,
        ])->assertCreated()->assertJsonPath('data.card.remaining_value', '60.00');
        $paymentId = $response->json('data.payment_id');
        $this->assertDatabaseHas('package_payments', ['id' => $paymentId, 'service_package_id' => $package, 'amount_mkd' => '2464.00']);
        $this->getJson("/api/v1/admin/gift-cards/{$card->id}/redemptions")->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/admin/payments/{$paymentId}/void", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame('100.00', $card->fresh()->remaining_value);
        $this->assertDatabaseHas('gift_card_redemptions', ['package_payment_id' => $paymentId, 'void_reason' => 'Mistake']);
        $this->patchJson("/api/v1/admin/payments/{$paymentId}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame('100.00', $card->fresh()->remaining_value);
    }

    public function test_rejects_overdraft_and_disabled_cards_without_creating_payments(): void
    {
        [$card, $package] = $this->fixture();
        $this->postJson("/api/v1/admin/gift-cards/{$card->id}/redeem", ['amount_eur' => '101.00', 'service_package_id' => $package])->assertUnprocessable();
        $card->update(['status' => 'disabled']);
        $this->postJson("/api/v1/admin/gift-cards/{$card->id}/redeem", ['amount_eur' => '20.00', 'service_package_id' => $package])->assertUnprocessable();
        $this->assertDatabaseCount('gift_card_redemptions', 0);
        $this->assertDatabaseCount('package_payments', 0);
    }
}
