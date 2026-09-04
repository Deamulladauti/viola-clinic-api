<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task9ManualDiscountsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_sell_single_treatment_with_percentage_discount(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        [$admin, $client] = $this->users();
        $service = $this->singleService(100.00);
        $staff = $this->staff();
        $service->staff()->attach($staff->id);

        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'single',
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'date' => $date,
            'starts_at' => '10:00',
            'status' => 'confirmed',
            'sale_discount_type' => 'percent',
            'sale_discount_value' => 10,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.appointment.sale_original_price', 100)
            ->assertJsonPath('data.appointment.sale_discount_type', 'percent')
            ->assertJsonPath('data.appointment.sale_discount_value', 10)
            ->assertJsonPath('data.appointment.sale_discount_amount', 10)
            ->assertJsonPath('data.appointment.sale_final_price', 90)
            ->assertJsonPath('data.appointment.price', 90);

        $appointment = Appointment::query()->findOrFail(
            (int) $response->json('data.appointment.id')
        );

        $this->assertSame(100.0, (float) $appointment->sale_original_price);
        $this->assertSame('percent', $appointment->sale_discount_type);
        $this->assertSame(10.0, (float) $appointment->sale_discount_value);
        $this->assertSame(10.0, (float) $appointment->sale_discount_amount);
        $this->assertSame(90.0, (float) $appointment->sale_final_price);
        $this->assertSame(90.0, (float) $appointment->price);
        $this->assertSame(90.0, $appointment->remaining_to_pay);
    }

    public function test_admin_can_sell_package_with_fixed_discount_and_balance_uses_final_price(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450.00);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'sale_discount_type' => 'fixed',
            'sale_discount_value' => 30,
            'currency' => 'EUR',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.sale_original_price', 450)
            ->assertJsonPath('data.sale_discount_type', 'fixed')
            ->assertJsonPath('data.sale_discount_value', 30)
            ->assertJsonPath('data.sale_discount_amount', 30)
            ->assertJsonPath('data.sale_final_price', 420)
            ->assertJsonPath('data.price_total', 420)
            ->assertJsonPath('data.remaining_balance', 420);

        $package = ServicePackage::query()->findOrFail(
            (int) $response->json('data.id')
        );

        $this->assertSame(450.0, (float) $package->sale_original_price);
        $this->assertSame('fixed', $package->sale_discount_type);
        $this->assertSame(30.0, (float) $package->sale_discount_amount);
        $this->assertSame(420.0, (float) $package->sale_final_price);
        $this->assertSame(420.0, (float) $package->price_total);
        $this->assertSame(420.0, $package->remaining_to_pay);
    }

    public function test_discount_cannot_exceed_sale_price_rules(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(100.00);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'sale_discount_type' => 'percent',
            'sale_discount_value' => 101,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_discount_value']);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'sale_discount_type' => 'fixed',
            'sale_discount_value' => 101,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_discount_value']);
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 9 Admin',
            'email' => 'task9-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 9 Client',
            'email' => 'task9-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'name' => 'Task 9 '.uniqid(),
            'slug' => 'task-9-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function singleService(float $price): Service
    {
        return Service::query()->create([
            'service_category_id' => $this->category()->id,
            'name' => 'Task 9 Single '.uniqid(),
            'slug' => 'task-9-single-'.uniqid(),
            'duration_minutes' => 60,
            'price' => $price,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => false,
            'usage_type' => Service::USAGE_SINGLE,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_PER_APPOINTMENT,
        ]);
    }

    private function packageService(float $price): Service
    {
        return Service::query()->create([
            'service_category_id' => $this->category()->id,
            'name' => 'Task 9 Package '.uniqid(),
            'slug' => 'task-9-package-'.uniqid(),
            'duration_minutes' => 60,
            'price' => $price,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => true,
            'total_sessions' => 6,
            'usage_type' => Service::USAGE_SESSION,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_ANY_QUALIFIED,
        ]);
    }

    private function staff(): Staff
    {
        return Staff::query()->create([
            'name' => 'Task 9 Staff '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function schedule(Staff $staff, string $date): void
    {
        StaffSchedule::query()->create([
            'staff_id' => $staff->id,
            'weekday' => Carbon::parse($date)->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
        ]);
    }
}
