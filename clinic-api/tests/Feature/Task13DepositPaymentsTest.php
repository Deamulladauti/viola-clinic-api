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

class Task13DepositPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_and_later_payments_reduce_the_same_package_balance(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450.00);
        Sanctum::actingAs($admin, ['*']);

        $sale = $this->postJson('/api/v1/admin/packages/assign', [
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
                'note' => 'Deposit at sale.',
            ],
        ]);

        $sale
            ->assertCreated()
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.amount_paid', 100)
            ->assertJsonPath('data.remaining_balance', 290);

        $packageId = (int) $sale->json('data.id');

        $later = $this->postJson("/api/v1/admin/packages/{$packageId}/payments", [
            'amount' => 50,
            'method' => 'cash',
            'currency' => 'EUR',
            'note' => 'Second payment.',
        ]);

        $later
            ->assertOk()
            ->assertJsonPath('amount_paid', 150)
            ->assertJsonPath('remaining_balance', 240);

        $package = ServicePackage::query()->findOrFail($packageId);
        $this->assertSame(150.0, $package->amount_paid);
        $this->assertSame(240.0, $package->remaining_to_pay);
        $this->assertSame(2, PackagePayment::query()->where('service_package_id', $packageId)->count());
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_package_payment_history_includes_deposit_and_later_payment(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(390.00);
        Sanctum::actingAs($admin, ['*']);

        $sale = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'initial_payment' => [
                'amount' => 100,
                'method' => 'cash',
                'currency' => 'EUR',
                'note' => 'Initial deposit.',
            ],
        ])->assertCreated();

        $packageId = (int) $sale->json('data.id');

        $this->postJson("/api/v1/admin/packages/{$packageId}/payments", [
            'amount' => 75,
            'method' => 'cash',
            'currency' => 'EUR',
            'note' => 'Follow-up payment.',
        ])->assertOk();

        $history = $this->getJson("/api/v1/admin/packages/{$packageId}/payments?status=all");

        $history
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'package')
            ->assertJsonPath('data.0.amount', 75)
            ->assertJsonPath('data.0.notes', 'Follow-up payment.')
            ->assertJsonPath('data.0.is_voided', false)
            ->assertJsonPath('data.1.amount', 100)
            ->assertJsonPath('data.1.notes', 'Initial deposit.');
    }

    public function test_voiding_a_package_payment_restores_balance_and_keeps_audit_history(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(390.00);
        Sanctum::actingAs($admin, ['*']);

        $sale = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'currency' => 'EUR',
            'initial_payment' => [
                'amount' => 100,
                'method' => 'cash',
                'currency' => 'EUR',
                'note' => 'Deposit.',
            ],
        ])->assertCreated();

        $packageId = (int) $sale->json('data.id');

        $later = $this->postJson("/api/v1/admin/packages/{$packageId}/payments", [
            'amount' => 50,
            'method' => 'cash',
            'currency' => 'EUR',
            'note' => 'Duplicate payment for test.',
        ])->assertOk();

        $paymentId = (int) $later->json('payment.id');

        $this->patchJson("/api/v1/admin/payments/{$paymentId}/void", [
            'reason' => 'Duplicate payment entered by mistake.',
        ])
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.is_voided', true)
            ->assertJsonPath('payment.void_reason', 'Duplicate payment entered by mistake.');

        $package = ServicePackage::query()->findOrFail($packageId);
        $this->assertSame(100.0, $package->amount_paid);
        $this->assertSame(290.0, $package->remaining_to_pay);

        $this->assertDatabaseHas('package_payments', [
            'id' => $paymentId,
            'service_package_id' => $packageId,
            'void_reason' => 'Duplicate payment entered by mistake.',
            'voided_by_id' => $admin->id,
        ]);

        $history = $this->getJson("/api/v1/admin/packages/{$packageId}/payments?status=all");
        $history
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonPath('data.0.is_voided', true)
            ->assertJsonPath('data.0.void_reason', 'Duplicate payment entered by mistake.');
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 13 Admin',
            'email' => 'task13-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 13 Client',
            'email' => 'task13-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function packageService(float $price): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 13 '.uniqid(),
            'slug' => 'task-13-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => 'Task 13 Package '.uniqid(),
            'slug' => 'task-13-package-'.uniqid(),
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
