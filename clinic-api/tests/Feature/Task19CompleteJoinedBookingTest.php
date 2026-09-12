<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Models\PackageLog;
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

class Task19CompleteJoinedBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_completes_joined_visit_and_each_package_loses_exactly_one_session(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->packageService('Laser Arms', 30);
        $legs = $this->packageService('Laser Legs', 45);
        $staff = $this->staff([$arms, $legs]);
        $armsPackage = $this->package($client, $arms);
        $legsPackage = $this->package($client, $legs);
        $group = $this->group($client, $admin);

        $armsAppointment = $this->appointment($group, $client, $arms, $armsPackage, $staff, '10:00');
        $legsAppointment = $this->appointment($group, $client, $legs, $legsPackage, $staff, '10:30');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/v1/admin/booking-groups/{$group->id}/complete");

        $response
            ->assertOk()
            ->assertJsonPath('data.booking_group.treatment_count', 2)
            ->assertJsonPath('data.booking_group.completed_count', 2)
            ->assertJsonPath('data.booking_group.newly_completed_count', 2)
            ->assertJsonPath('data.booking_group.all_completed', true);

        $this->assertDatabaseHas('appointments', [
            'id' => $armsAppointment->id,
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('appointments', [
            'id' => $legsAppointment->id,
            'status' => Appointment::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('service_packages', [
            'id' => $armsPackage->id,
            'remaining_sessions' => 5,
        ]);
        $this->assertDatabaseHas('service_packages', [
            'id' => $legsPackage->id,
            'remaining_sessions' => 5,
        ]);

        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $armsPackage->id,
            'appointment_id' => $armsAppointment->id,
            'used_sessions' => 1,
            'session_number' => 1,
            'voided_at' => null,
        ]);
        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $legsPackage->id,
            'appointment_id' => $legsAppointment->id,
            'used_sessions' => 1,
            'session_number' => 1,
            'voided_at' => null,
        ]);
        $this->assertSame(2, PackageLog::query()->whereNull('voided_at')->count());

        // Completing the same joined visit again is safe. The centralized
        // completion service reuses the existing appointment ledger entry and
        // therefore cannot consume a second session by accident.
        $this->patchJson("/api/v1/admin/booking-groups/{$group->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.booking_group.newly_completed_count', 0);

        $this->assertSame(5, (int) $armsPackage->refresh()->remaining_sessions);
        $this->assertSame(5, (int) $legsPackage->refresh()->remaining_sessions);
        $this->assertSame(2, PackageLog::query()->whereNull('voided_at')->count());
    }

    public function test_if_one_treatment_cannot_complete_the_whole_joined_completion_rolls_back(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->packageService('Laser Arms', 30);
        $legs = $this->packageService('Laser Legs', 45);
        $staff = $this->staff([$arms, $legs]);
        $armsPackage = $this->package($client, $arms);
        $legsPackage = $this->package($client, $legs);
        $legsPackage->forceFill([
            'remaining_sessions' => 0,
            'status' => ServicePackage::STATUS_EXHAUSTED,
        ])->save();

        $group = $this->group($client, $admin);
        $armsAppointment = $this->appointment($group, $client, $arms, $armsPackage, $staff, '10:00');
        $legsAppointment = $this->appointment($group, $client, $legs, $legsPackage, $staff, '10:30');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/v1/admin/booking-groups/{$group->id}/complete");

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appointments.1.service_package_id']);

        // Appointment 1 was attempted before appointment 2 failed, so these
        // assertions prove the OUTER group transaction restored everything.
        $this->assertSame(Appointment::STATUS_CONFIRMED, $armsAppointment->refresh()->status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $legsAppointment->refresh()->status);
        $this->assertSame(6, (int) $armsPackage->refresh()->remaining_sessions);
        $this->assertSame(0, (int) $legsPackage->refresh()->remaining_sessions);
        $this->assertSame(0, PackageLog::query()->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 19 Admin',
            'email' => 'task19-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 19 Client',
            'email' => 'task19-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function packageService(string $name, int $duration): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 19 '.uniqid(),
            'slug' => 'task-19-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-19-service-'.uniqid(),
            'duration_minutes' => $duration,
            'price' => 200,
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

    private function staff(array $services): Staff
    {
        $staff = Staff::query()->create([
            'name' => 'Task 19 Staff '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($services as $service) {
            $service->staff()->attach($staff->id);
        }

        return $staff;
    }

    private function package(User $client, Service $service): ServicePackage
    {
        return ServicePackage::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_total_minutes' => null,
            'remaining_sessions' => 6,
            'remaining_minutes' => null,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'snapshot_minimum_interval_days' => 0,
            'snapshot_deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'snapshot_staff_policy' => Service::STAFF_ANY_QUALIFIED,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'price_total' => 450,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => Carbon::today()->subMonth()->toDateString(),
        ]);
    }

    private function group(User $client, User $admin): BookingGroup
    {
        return BookingGroup::query()->create([
            'user_id' => $client->id,
            'created_by_user_id' => $admin->id,
        ]);
    }

    private function appointment(
        BookingGroup $group,
        User $client,
        Service $service,
        ServicePackage $package,
        Staff $staff,
        string $startsAt,
    ): Appointment {
        return Appointment::query()->forceCreate([
            'booking_group_id' => $group->id,
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'staff_id' => $staff->id,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'customer_phone' => $client->phone,
            'reference_code' => 'T19-'.strtoupper(uniqid()),
            'date' => Carbon::today()->toDateString(),
            'starts_at' => $startsAt,
            'duration_minutes' => $service->duration_minutes,
            'price' => 450,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
        ]);
    }
}
