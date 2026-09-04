<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Offer;
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

class Task10OffersPromotionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_create_offer_and_it_is_returned_as_eligible_for_selected_service(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        [$admin] = $this->users();
        $service = $this->packageService(450.00);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/offers', [
            'name' => 'Full Body September',
            'description' => 'September package offer',
            'pricing_type' => 'fixed_price',
            'value' => 390,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'is_active' => true,
            'service_ids' => [$service->id],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Full Body September')
            ->assertJsonPath('data.pricing_type', 'fixed_price')
            ->assertJsonPath('data.value', 390)
            ->assertJsonPath('data.is_currently_active', true)
            ->assertJsonPath('data.services.0.id', $service->id);

        $offerId = (int) $response->json('data.id');

        $this->getJson("/api/v1/admin/offers/eligible?service_id={$service->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $offerId)
            ->assertJsonPath('data.0.regular_price', 450)
            ->assertJsonPath('data.0.discount_amount', 60)
            ->assertJsonPath('data.0.final_price', 390);
    }

    public function test_single_treatment_offer_is_snapshotted_and_later_offer_changes_do_not_change_sale(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        [$admin, $client] = $this->users();
        $service = $this->singleService(100.00);
        $staff = $this->staff();
        $service->staff()->attach($staff->id);
        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);

        $offer = Offer::query()->create([
            'name' => 'September 20%',
            'pricing_type' => Offer::TYPE_PERCENT,
            'value' => 20,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $offer->services()->attach($service->id);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'single',
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'date' => $date,
            'starts_at' => '10:00',
            'status' => 'confirmed',
            'offer_id' => $offer->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.appointment.sale_original_price', 100)
            ->assertJsonPath('data.appointment.sale_discount_type', 'percent')
            ->assertJsonPath('data.appointment.sale_discount_value', 20)
            ->assertJsonPath('data.appointment.sale_discount_amount', 20)
            ->assertJsonPath('data.appointment.sale_final_price', 80)
            ->assertJsonPath('data.appointment.sale_offer_id', $offer->id)
            ->assertJsonPath('data.appointment.sale_offer_name', 'September 20%');

        $appointment = Appointment::query()->findOrFail((int) $response->json('data.appointment.id'));

        $service->update(['price' => 130]);
        $offer->update(['name' => 'Changed later', 'value' => 5, 'is_active' => false]);
        $appointment->refresh();

        $this->assertSame(100.0, (float) $appointment->sale_original_price);
        $this->assertSame(80.0, (float) $appointment->sale_final_price);
        $this->assertSame(80.0, (float) $appointment->price);
        $this->assertSame('September 20%', $appointment->sale_offer_name);
        $this->assertSame(80.0, $appointment->remaining_to_pay);
    }

    public function test_package_offer_price_is_snapshotted_and_balance_uses_final_offer_price(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        [$admin, $client] = $this->users();
        $service = $this->packageService(450.00);

        $offer = Offer::query()->create([
            'name' => 'Laser package €390',
            'pricing_type' => Offer::TYPE_FIXED_PRICE,
            'value' => 390,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $offer->services()->attach($service->id);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'offer_id' => $offer->id,
            'currency' => 'EUR',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.sale_original_price', 450)
            ->assertJsonPath('data.sale_discount_type', 'fixed')
            ->assertJsonPath('data.sale_discount_amount', 60)
            ->assertJsonPath('data.sale_final_price', 390)
            ->assertJsonPath('data.sale_offer_id', $offer->id)
            ->assertJsonPath('data.sale_offer_name', 'Laser package €390')
            ->assertJsonPath('data.remaining_balance', 390);

        $package = ServicePackage::query()->findOrFail((int) $response->json('data.id'));
        $service->update(['price' => 500]);
        $offer->update(['value' => 350, 'is_active' => false]);
        $package->refresh();

        $this->assertSame(450.0, (float) $package->sale_original_price);
        $this->assertSame(390.0, (float) $package->sale_final_price);
        $this->assertSame(390.0, (float) $package->price_total);
        $this->assertSame('Laser package €390', $package->sale_offer_name);
        $this->assertSame(390.0, $package->remaining_to_pay);
    }

    public function test_inactive_or_wrong_service_offer_is_rejected_and_offer_cannot_be_combined_with_manual_discount(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        [$admin, $client] = $this->users();
        $service = $this->packageService(200.00);
        $other = $this->packageService(300.00);

        $offer = Offer::query()->create([
            'name' => 'Wrong service',
            'pricing_type' => Offer::TYPE_PERCENT,
            'value' => 10,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $offer->services()->attach($other->id);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'offer_id' => $offer->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offer_id']);

        $offer->services()->sync([$service->id]);
        $offer->update(['is_active' => false]);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'offer_id' => $offer->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offer_id']);

        $offer->update(['is_active' => true]);

        $this->postJson('/api/v1/admin/packages/assign', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'offer_id' => $offer->id,
            'sale_discount_type' => 'fixed',
            'sale_discount_value' => 10,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offer_id']);
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 10 Admin',
            'email' => 'task10-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 10 Client',
            'email' => 'task10-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::query()->create([
            'name' => 'Task 10 '.uniqid(),
            'slug' => 'task-10-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function singleService(float $price): Service
    {
        return Service::query()->create([
            'service_category_id' => $this->category()->id,
            'name' => 'Task 10 Single '.uniqid(),
            'slug' => 'task-10-single-'.uniqid(),
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
            'name' => 'Task 10 Package '.uniqid(),
            'slug' => 'task-10-package-'.uniqid(),
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
            'name' => 'Task 10 Staff '.uniqid(),
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
