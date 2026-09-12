<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingGroup;
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

class Task16AtomicBookingGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_create_two_treatments_as_one_atomic_booking_group(): void
    {
        Carbon::setTestNow('2026-09-11 10:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms', 30, 180.00, false);
        $legs = $this->service('Laser Legs', 45, 250.00, false);
        $staff = $this->qualifiedStaff([$arms, $legs]);
        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/booking-groups", [
            'date' => $date,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'treatments' => [
                [
                    'purchase_type' => 'single',
                    'service_id' => $arms->id,
                ],
                [
                    'purchase_type' => 'single',
                    'service_id' => $legs->id,
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.booking_group.treatment_count', 2)
            ->assertJsonPath('data.booking_group.total_duration_minutes', 75)
            ->assertJsonPath('data.appointments.0.starts_at', '10:00')
            ->assertJsonPath('data.appointments.1.starts_at', '10:30');

        $groupId = (int) $response->json('data.booking_group.id');
        $appointmentIds = $response->json('data.booking_group.appointment_ids');

        $this->assertDatabaseHas('booking_groups', [
            'id' => $groupId,
            'user_id' => $client->id,
            'created_by_user_id' => $admin->id,
        ]);

        $this->assertCount(2, $appointmentIds);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentIds[0],
            'booking_group_id' => $groupId,
            'service_id' => $arms->id,
        ]);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentIds[1],
            'booking_group_id' => $groupId,
            'service_id' => $legs->id,
        ]);

        // SQLite preserves HH:MM while MySQL TIME commonly returns HH:MM:SS.
        // Assert the actual clock time without tying the test to one driver.
        $firstAppointment = Appointment::query()->findOrFail($appointmentIds[0]);
        $secondAppointment = Appointment::query()->findOrFail($appointmentIds[1]);

        $this->assertSame('10:00', substr((string) $firstAppointment->starts_at, 0, 5));
        $this->assertSame('10:30', substr((string) $secondAppointment->starts_at, 0, 5));
    }

    public function test_if_later_treatment_fails_the_group_and_every_earlier_treatment_are_rolled_back(): void
    {
        Carbon::setTestNow('2026-09-11 10:00:00');

        [$admin, $client] = $this->users();
        $first = $this->service('First Treatment', 30, 80.00, false);
        $second = $this->service('Second Package Treatment', 45, 250.00, true);
        $wrongPackageService = $this->service('Different Package Treatment', 45, 200.00, true);
        $wrongPackage = $this->package($client, $wrongPackageService);
        $staff = $this->qualifiedStaff([$first, $second, $wrongPackageService]);
        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/booking-groups", [
            'date' => $date,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'treatments' => [
                [
                    'purchase_type' => 'single',
                    'service_id' => $first->id,
                ],
                [
                    'purchase_type' => 'existing_package',
                    'service_id' => $second->id,
                    // This id exists, so request validation passes, but the
                    // canonical appointment service rejects the package/service
                    // mismatch after treatment 1 has already been attempted.
                    'service_package_id' => $wrongPackage->id,
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['treatments.1.service_package_id']);

        $this->assertSame(0, BookingGroup::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertDatabaseHas('service_packages', ['id' => $wrongPackage->id]);
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 16 Admin',
            'email' => 'task16-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 16 Client',
            'email' => 'task16-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function service(string $name, int $duration, float $price, bool $package): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 16 '.uniqid(),
            'slug' => 'task-16-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-16-service-'.uniqid(),
            'duration_minutes' => $duration,
            'price' => $price,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => $package,
            'total_sessions' => $package ? 6 : null,
            'usage_type' => $package ? Service::USAGE_SESSION : Service::USAGE_SINGLE,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => $package ? Service::STAFF_ANY_QUALIFIED : Service::STAFF_PER_APPOINTMENT,
        ]);
    }

    private function qualifiedStaff(array $services): Staff
    {
        $staff = Staff::query()->create([
            'name' => 'Task 16 Staff '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($services as $service) {
            $service->staff()->attach($staff->id);
        }

        return $staff;
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
            'price_total' => $service->price,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => Carbon::today()->toDateString(),
        ]);
    }
}
