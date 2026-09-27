<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task38GiftCardAdminTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::query()->create(['name' => 'Test '. $role, 'email' => uniqid($role).'@example.com', 'password' => 'password']);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    public function test_admin_issues_lists_and_disables_card_without_mutating_value(): void
    {
        $admin = $this->signIn('admin');
        $response = $this->postJson('/api/v1/admin/gift-cards', [
            'initial_value' => '100.00', 'recipient_name' => 'Test Recipient',
        ])->assertCreated()->assertJsonPath('data.remaining_value', '100.00')
          ->assertJsonPath('data.status', 'active');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('gift_cards', ['id' => $id, 'created_by_id' => $admin->id]);
        $this->getJson('/api/v1/admin/gift-cards?q=Recipient')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/gift-cards/{$id}")->assertOk()->assertJsonPath('data.initial_value', '100.00');
        $this->patchJson("/api/v1/admin/gift-cards/{$id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->patchJson("/api/v1/admin/gift-cards/{$id}/enable")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('100.00', GiftCard::findOrFail($id)->remaining_value);
    }

    public function test_invalid_values_and_non_admin_access_are_rejected(): void
    {
        $this->signIn('admin');
        $this->postJson('/api/v1/admin/gift-cards', ['initial_value' => -2])->assertUnprocessable();
        $this->postJson('/api/v1/admin/gift-cards', ['initial_value' => '0.001'])->assertUnprocessable();
        $this->signIn('client');
        $this->getJson('/api/v1/admin/gift-cards')->assertForbidden();
        $this->postJson('/api/v1/admin/gift-cards', ['initial_value' => 100])->assertForbidden();
    }
}
