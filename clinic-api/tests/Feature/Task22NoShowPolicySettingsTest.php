<?php

namespace Tests\Feature;

use App\Models\NoShowPolicySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task22NoShowPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_and_update_the_clinic_no_show_policy(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/admin/settings/no-show-policy')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.misses_before_penalty', 2)
            ->assertJsonPath('data.sessions_to_deduct', 1);

        $this->patchJson('/api/v1/admin/settings/no-show-policy', [
            'is_enabled' => true,
            'misses_before_penalty' => 3,
            'sessions_to_deduct' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.misses_before_penalty', 3)
            ->assertJsonPath('data.sessions_to_deduct', 1);

        $this->assertDatabaseHas('no_show_policy_settings', [
            'id' => 1,
            'is_enabled' => 1,
            'misses_before_penalty' => 3,
            'sessions_to_deduct' => 1,
            'updated_by_user_id' => $admin->id,
        ]);

        $this->assertSame(1, NoShowPolicySetting::query()->count());
    }

    public function test_policy_values_must_be_positive_and_cancelled_is_not_configurable_as_a_miss(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/no-show-policy', [
            'is_enabled' => true,
            'misses_before_penalty' => 0,
            'sessions_to_deduct' => 0,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'misses_before_penalty',
                'sessions_to_deduct',
            ]);

        $this->patchJson('/api/v1/admin/settings/no-show-policy', [
            'is_enabled' => true,
            'misses_before_penalty' => 2,
            'sessions_to_deduct' => 1,
            'cancelled_counts_as_miss' => true,
        ])->assertOk();

        // There is deliberately no configurable cancellation flag in V1.
        // Only appointment status=no_show is eligible for later Task 23/24 logic.
        $this->assertArrayNotHasKey(
            'cancelled_counts_as_miss',
            NoShowPolicySetting::current()->toArray(),
        );
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::query()->create([
            'name' => 'Task 22 Admin',
            'email' => 'task22-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
