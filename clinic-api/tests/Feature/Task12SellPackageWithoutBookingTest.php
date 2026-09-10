<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task12SellPackageWithoutBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sell_package_without_creating_an_appointment(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450.00);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'starts_on' => now()->toDateString(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user_id', $client->id)
            ->assertJsonPath('data.service_id', $service->id)
            ->assertJsonPath('data.sale_final_price', 450)
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.remaining_balance', 450)
            ->assertJsonPath('data.initial_payment', null);

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, PackagePayment::query()->count());
        $this->assertSame(1, ServicePackage::query()->count());
    }

    public function test_admin_can_sell_discounted_package_with_initial_deposit_without_appointment(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450.00);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'starts_on' => now()->toDateString(),
            'sale_discount_type' => 'fixed',
            'sale_discount_value' => 60,
            'initial_payment' => [
                'amount' => 100,
                'method' => 'cash',
                'currency' => 'EUR',
                'note' => 'Deposit at package sale.',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.sale_original_price', 450)
            ->assertJsonPath('data.sale_discount_amount', 60)
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.amount_paid', 100)
            ->assertJsonPath('data.remaining_balance', 290)
            ->assertJsonPath('data.initial_payment.amount', 100)
            ->assertJsonPath('data.initial_payment.method', 'cash')
            ->assertJsonPath('data.initial_payment.currency', 'EUR');

        $packageId = (int) $response->json('data.id');

        $this->assertSame(0, Appointment::query()->count());
        $this->assertDatabaseHas('package_payments', [
            'service_package_id' => $packageId,
            'appointment_id' => null,
            'user_id' => $client->id,
            'admin_id' => $admin->id,
            'method' => 'cash',
            'amount' => 100,
            'currency' => 'EUR',
            'notes' => 'Deposit at package sale.',
        ]);

        $package = ServicePackage::query()->findOrFail($packageId);
        $this->assertSame(390.0, (float) $package->sale_final_price);
        $this->assertSame(100.0, $package->amount_paid);
        $this->assertSame(290.0, $package->remaining_to_pay);
    }

    public function test_admin_can_pay_package_in_full_at_sale_without_booking(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(390.00);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'initial_payment' => [
                'amount' => 390,
                'method' => 'cash',
                'currency' => 'EUR',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.amount_paid', 390)
            ->assertJsonPath('data.remaining_balance', 0);

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(1, PackagePayment::query()->count());
    }

    public function test_invalid_initial_payment_does_not_leave_half_created_package(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(390.00);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'initial_payment' => [
                'amount' => 500,
                'method' => 'cash',
                'currency' => 'EUR',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['initial_payment.amount']);

        $this->assertSame(0, ServicePackage::query()->count());
        $this->assertSame(0, PackagePayment::query()->count());
        $this->assertSame(0, Appointment::query()->count());
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 12 Admin',
            'email' => 'task12-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 12 Client',
            'email' => 'task12-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'name' => 'Task 12 '.uniqid(),
            'slug' => 'task-12-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function packageService(float $price): Service
    {
        return Service::query()->create([
            'service_category_id' => $this->category()->id,
            'name' => 'Task 12 Package '.uniqid(),
            'slug' => 'task-12-package-'.uniqid(),
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
}
