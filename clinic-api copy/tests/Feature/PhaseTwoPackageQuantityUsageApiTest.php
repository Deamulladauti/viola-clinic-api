<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseTwoPackageQuantityUsageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_record_historical_minute_usage_without_an_appointment(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('minutes');
        $staff = Staff::query()->create(['name' => 'Solarium Staff', 'is_active' => true]);
        $package = $this->createPackage($client, $service, minutes: 50);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/packages/{$package->id}/usage", [
            'minutes' => 10,
            'occurred_on' => Carbon::yesterday()->toDateString(),
            'staff_id' => $staff->id,
            'note' => 'Historical solarium use',
            'source' => 'imported',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('package_id', $package->id)
            ->assertJsonPath('remaining_minutes', 40)
            ->assertJsonPath('used_amount', 10)
            ->assertJsonPath('type', 'minutes')
            ->assertJsonPath('data.usage.usage_type', 'minutes')
            ->assertJsonPath('data.usage.quantity', 10)
            ->assertJsonPath('data.package.remaining_minutes', 40);

        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $package->id,
            'appointment_id' => null,
            'usage_type' => 'minutes',
            'quantity' => 10,
            'source' => 'imported',
        ]);

        $usage = \App\Models\PackageLog::query()
            ->where('service_package_id', $package->id)
            ->whereNull('appointment_id')
            ->firstOrFail();

        $this->assertSame(
            Carbon::yesterday()->toDateString(),
            $usage->occurred_on->toDateString(),
        );
    }

    public function test_quantity_usage_cannot_exceed_the_remaining_minutes(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('minutes');
        $package = $this->createPackage($client, $service, minutes: 5);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/admin/packages/{$package->id}/usage", [
            'minutes' => 10,
            'occurred_on' => Carbon::today()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minutes']);

        $this->assertDatabaseHas('service_packages', [
            'id' => $package->id,
            'remaining_minutes' => 5,
            'status' => ServicePackage::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('package_logs', 0);
    }

    public function test_legacy_use_route_rejects_manual_session_deduction(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session');
        $package = ServicePackage::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'remaining_sessions' => 6,
            'snapshot_usage_type' => 'session',
            'snapshot_minimum_interval_days' => 0,
            'snapshot_deduction_method' => 'automatic_on_completion',
            'snapshot_staff_policy' => 'any_qualified_staff',
            'price_total' => 300,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/admin/packages/{$package->id}/use", [
            'type' => 'sessions',
            'amount' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertDatabaseHas('service_packages', [
            'id' => $package->id,
            'remaining_sessions' => 6,
        ]);
        $this->assertDatabaseCount('package_logs', 0);
    }

    private function createUsers(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Quantity Admin',
            'email' => 'quantity-admin@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Quantity Client',
            'email' => 'quantity-client@example.com',
            'password' => 'password',
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function createService(string $usageType): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Quantity Category ' . uniqid(),
            'slug' => 'quantity-' . uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => 'Quantity ' . ucfirst($usageType) . ' ' . uniqid(),
            'duration_minutes' => 10,
            'price' => 50,
            'is_active' => true,
            'is_bookable' => $usageType !== 'minutes',
            'is_package' => true,
            'total_sessions' => $usageType === 'session' ? 6 : null,
            'total_minutes' => $usageType === 'minutes' ? 50 : null,
            'usage_type' => $usageType,
            'minimum_interval_days' => 0,
            'deduction_method' => $usageType === 'minutes' ? 'manual' : 'automatic_on_completion',
            'staff_policy' => 'any_qualified_staff',
        ]);
    }

    private function createPackage(User $client, Service $service, int $minutes): ServicePackage
    {
        return ServicePackage::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => null,
            'snapshot_total_minutes' => 50,
            'remaining_sessions' => null,
            'remaining_minutes' => $minutes,
            'snapshot_usage_type' => 'minutes',
            'snapshot_minimum_interval_days' => 0,
            'snapshot_deduction_method' => 'manual',
            'snapshot_staff_policy' => 'any_qualified_staff',
            'price_total' => 50,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => Carbon::now()->subMonth()->toDateString(),
        ]);
    }
}
