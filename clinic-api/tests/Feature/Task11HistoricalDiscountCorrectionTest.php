<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\SalePriceCorrection;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task11HistoricalDiscountCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_correct_old_package_price_and_remove_phantom_balance_without_fake_payment(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450);

        $package = ServicePackage::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'price_total' => 450,
            'sale_original_price' => 450,
            'sale_final_price' => 450,
            'currency' => 'EUR',
            'remaining_sessions' => 6,
            'status' => ServicePackage::STATUS_ACTIVE,
        ]);

        PackagePayment::query()->create([
            'service_package_id' => $package->id,
            'user_id' => $client->id,
            'admin_id' => $admin->id,
            'method' => 'cash',
            'amount' => 390,
            'currency' => 'EUR',
            'exchange_rate' => ServicePackage::EUR_TO_MKD,
            'amount_mkd' => 390 * ServicePackage::EUR_TO_MKD,
        ]);

        $package->refresh();
        $this->assertSame(60.0, $package->remaining_to_pay);
        $paymentCountBefore = PackagePayment::query()->count();

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/packages/{$package->id}/sale-price", [
            'final_price' => 390,
            'reason' => 'Client bought this during an old €390 promotion.',
        ])
            ->assertOk()
            ->assertJsonPath('data.sale_original_price', 450)
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.sale_discount_amount', 60)
            ->assertJsonPath('data.remaining_balance', 0);

        $package->refresh();
        $this->assertSame(390.0, (float) $package->price_total);
        $this->assertSame(390.0, (float) $package->sale_final_price);
        $this->assertSame(0.0, $package->remaining_to_pay);
        $this->assertSame($paymentCountBefore, PackagePayment::query()->count());

        $this->assertDatabaseHas('sale_price_corrections', [
            'subject_type' => SalePriceCorrection::SUBJECT_PACKAGE,
            'subject_id' => $package->id,
            'corrected_by' => $admin->id,
            'reason' => 'Client bought this during an old €390 promotion.',
        ]);
    }

    public function test_admin_can_correct_old_single_treatment_price_and_audit_it(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->singleService(100);

        $appointment = Appointment::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'date' => now()->subMonth()->toDateString(),
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 100,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'reference_code' => 'TASK11SINGLE',
            'sale_original_price' => 100,
            'sale_final_price' => 100,
            'status' => Appointment::STATUS_COMPLETED,
            'source' => Appointment::SOURCE_MANUAL_IMPORT,
        ]);

        PackagePayment::query()->create([
            'service_package_id' => null,
            'appointment_id' => $appointment->id,
            'user_id' => $client->id,
            'admin_id' => $admin->id,
            'method' => 'cash',
            'amount' => 80,
            'currency' => 'EUR',
            'exchange_rate' => ServicePackage::EUR_TO_MKD,
            'amount_mkd' => 80 * ServicePackage::EUR_TO_MKD,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/sale-price", [
            'final_price' => 80,
            'reason' => 'Historical agreed treatment price was €80.',
        ])
            ->assertOk()
            ->assertJsonPath('data.sale_original_price', 100)
            ->assertJsonPath('data.sale_final_price', 80)
            ->assertJsonPath('data.remaining_balance', 0);

        $appointment->refresh();
        $this->assertSame(80.0, (float) $appointment->price);
        $this->assertSame(80.0, (float) $appointment->sale_final_price);

        $this->getJson("/api/v1/admin/appointments/{$appointment->id}/sale-price-corrections")
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Historical agreed treatment price was €80.')
            ->assertJsonPath('data.0.before_terms.final_price', 100)
            ->assertJsonPath('data.0.after_terms.final_price', 80);
    }

    public function test_correction_cannot_hide_an_overpayment_or_raise_the_price(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450);

        $package = ServicePackage::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'price_total' => 450,
            'sale_original_price' => 450,
            'sale_final_price' => 450,
            'currency' => 'EUR',
            'remaining_sessions' => 6,
            'status' => ServicePackage::STATUS_ACTIVE,
        ]);

        PackagePayment::query()->create([
            'service_package_id' => $package->id,
            'user_id' => $client->id,
            'admin_id' => $admin->id,
            'method' => 'cash',
            'amount' => 400,
            'currency' => 'EUR',
            'exchange_rate' => ServicePackage::EUR_TO_MKD,
            'amount_mkd' => 400 * ServicePackage::EUR_TO_MKD,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/packages/{$package->id}/sale-price", [
            'final_price' => 390,
            'reason' => 'Trying to set below paid amount.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['final_price']);

        $this->patchJson("/api/v1/admin/packages/{$package->id}/sale-price", [
            'final_price' => 460,
            'reason' => 'Trying to increase historical price.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['final_price']);
    }

    public function test_package_linked_appointment_must_be_corrected_through_package(): void
    {
        [$admin, $client] = $this->users();
        $service = $this->packageService(450);
        $package = ServicePackage::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'price_total' => 450,
            'currency' => 'EUR',
            'remaining_sessions' => 6,
            'status' => ServicePackage::STATUS_ACTIVE,
        ]);

        $appointment = Appointment::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => now()->addDay()->toDateString(),
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 450,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'reference_code' => 'TASK11PACKAGE',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/sale-price", [
            'final_price' => 390,
            'reason' => 'Wrong financial target.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['final_price']);
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 11 Admin',
            'email' => 'task11-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 11 Client',
            'email' => 'task11-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'name' => 'Task 11 '.uniqid(),
            'slug' => 'task-11-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function singleService(float $price): Service
    {
        return Service::query()->create([
            'service_category_id' => $this->category()->id,
            'name' => 'Task 11 Single '.uniqid(),
            'slug' => 'task-11-single-'.uniqid(),
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
            'name' => 'Task 11 Package '.uniqid(),
            'slug' => 'task-11-package-'.uniqid(),
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
