<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\PackagePayment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Task8SalePriceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_treatment_keeps_its_sale_terms_when_service_price_changes(): void
    {
        [$client, $service] = $this->makeClientAndService(100.00, false);

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'user_id' => $client->id,
            'staff_id' => null,
            'date' => now()->addDay()->toDateString(),
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 80.00,
            'customer_name' => $client->name,
            'customer_phone' => $client->phone,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'reference_code' => 'T8SINGLE001',
        ]);

        $this->assertSame(100.0, (float) $appointment->sale_original_price);
        $this->assertSame('fixed', $appointment->sale_discount_type);
        $this->assertSame(20.0, (float) $appointment->sale_discount_amount);
        $this->assertSame(80.0, (float) $appointment->sale_final_price);

        PackagePayment::create([
            'appointment_id' => $appointment->id,
            'service_package_id' => null,
            'user_id' => $client->id,
            'method' => 'cash',
            'amount' => 30.00,
            'currency' => 'EUR',
        ]);

        $this->assertSame(50.0, $appointment->fresh()->remaining_to_pay);

        $service->update(['price' => 150.00]);
        $appointment = $appointment->fresh();

        $this->assertSame(100.0, (float) $appointment->sale_original_price);
        $this->assertSame(80.0, (float) $appointment->sale_final_price);
        $this->assertSame(50.0, $appointment->remaining_to_pay);
    }

    public function test_package_keeps_its_sale_terms_and_balance_when_service_price_changes(): void
    {
        [$client, $service] = $this->makeClientAndService(450.00, true);

        $package = ServicePackage::create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'snapshot_deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'snapshot_staff_policy' => Service::STAFF_ANY_QUALIFIED,
            'snapshot_duration_minutes' => 60,
            'remaining_sessions' => 6,
            'price_total' => 390.00,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => now()->toDateString(),
        ]);

        $this->assertSame(450.0, (float) $package->sale_original_price);
        $this->assertSame('fixed', $package->sale_discount_type);
        $this->assertSame(60.0, (float) $package->sale_discount_amount);
        $this->assertSame(390.0, (float) $package->sale_final_price);

        PackagePayment::create([
            'service_package_id' => $package->id,
            'appointment_id' => null,
            'user_id' => $client->id,
            'method' => 'cash',
            'amount' => 100.00,
            'currency' => 'EUR',
            'exchange_rate' => ServicePackage::EUR_TO_MKD,
            'amount_mkd' => 100.00 * ServicePackage::EUR_TO_MKD,
        ]);

        $this->assertSame(290.0, $package->fresh()->remaining_to_pay);

        $service->update(['price' => 500.00]);
        $package = $package->fresh();

        $this->assertSame(450.0, (float) $package->sale_original_price);
        $this->assertSame(390.0, (float) $package->sale_final_price);
        $this->assertSame(290.0, $package->remaining_to_pay);
    }

    private function makeClientAndService(float $price, bool $package): array
    {
        $category = ServiceCategory::create([
            'name' => 'Task 8',
            'slug' => 'task-8-'.uniqid(),
            'is_active' => true,
        ]);

        $service = Service::create([
            'service_category_id' => $category->id,
            'name' => $package ? 'Task 8 Package' : 'Task 8 Single',
            'slug' => 'task-8-service-'.uniqid(),
            'duration_minutes' => 60,
            'price' => $price,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => $package,
            'total_sessions' => $package ? 6 : null,
            'usage_type' => $package ? Service::USAGE_SESSION : Service::USAGE_SINGLE,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => $package ? Service::STAFF_ANY_QUALIFIED : Service::STAFF_PER_APPOINTMENT,
        ]);

        $client = User::create([
            'name' => 'Task 8 Client',
            'email' => 'task8-'.uniqid().'@example.com',
            'phone' => '38970'.random_int(100000, 999999),
            'password' => 'password',
        ]);

        return [$client, $service];
    }
}
