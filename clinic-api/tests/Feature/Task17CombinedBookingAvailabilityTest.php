<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task17CombinedBookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_availability_uses_the_entire_combined_duration(): void
    {
        Carbon::setTestNow('2026-09-12 08:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms', 30);
        $legs = $this->service('Laser Legs', 45);
        $blockerService = $this->service('Existing Treatment', 30);
        $staff = $this->qualifiedStaff([$arms, $legs, $blockerService]);
        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);

        // The first 30-minute treatment would fit at 10:00, but the complete
        // joined visit runs until 11:15 and therefore collides with this booking.
        $this->existingAppointment($client, $blockerService, $staff, $date, '10:45', 30);

        Sanctum::actingAs($admin, ['*']);

        $query = http_build_query([
            'date' => $date,
            'staff_id' => $staff->id,
            'service_ids' => [$arms->id, $legs->id],
            'step' => 15,
        ]);

        $response = $this->getJson("/api/v1/admin/booking-groups/availability?{$query}");

        $response
            ->assertOk()
            ->assertJsonPath('data.total_duration_minutes', 75);

        $slots = $response->json('data.available_slots');
        $this->assertContains('09:30', $slots); // ends exactly at 10:45
        $this->assertNotContains('10:00', $slots); // would run through 11:15
    }

    public function test_creation_rejects_a_start_time_when_the_full_block_does_not_fit(): void
    {
        Carbon::setTestNow('2026-09-12 08:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms', 30);
        $legs = $this->service('Laser Legs', 45);
        $blockerService = $this->service('Existing Treatment', 30);
        $staff = $this->qualifiedStaff([$arms, $legs, $blockerService]);
        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);
        $this->existingAppointment($client, $blockerService, $staff, $date, '10:45', 30);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/booking-groups", [
            'date' => $date,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'treatments' => [
                ['purchase_type' => 'single', 'service_id' => $arms->id],
                ['purchase_type' => 'single', 'service_id' => $legs->id],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertSame(0, BookingGroup::query()->count());
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_later_treatment_segment_respects_the_existing_service_resource_guard(): void
    {
        Carbon::setTestNow('2026-09-12 08:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms', 30);
        $legs = $this->service('Laser Legs', 45);
        $staff = $this->qualifiedStaff([$arms, $legs]);
        $otherStaff = Staff::query()->create([
            'name' => 'Other Staff '.uniqid(),
            'is_active' => true,
        ]);
        $legs->staff()->attach($otherStaff->id);

        $date = Carbon::tomorrow()->toDateString();
        $this->schedule($staff, $date);
        $this->schedule($otherStaff, $date);

        // Staff A is free, but the Legs segment (10:30-11:15) collides with an
        // existing Legs appointment owned by Staff B. This preserves the app's
        // existing same-service resource guard for joined bookings.
        $this->existingAppointment($client, $legs, $otherStaff, $date, '10:35', 25);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/booking-groups", [
            'date' => $date,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'treatments' => [
                ['purchase_type' => 'single', 'service_id' => $arms->id],
                ['purchase_type' => 'single', 'service_id' => $legs->id],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertSame(0, BookingGroup::query()->count());
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 17 Admin',
            'email' => 'task17-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 17 Client',
            'email' => 'task17-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function service(string $name, int $duration): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 17 '.uniqid(),
            'slug' => 'task-17-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-17-service-'.uniqid(),
            'duration_minutes' => $duration,
            'price' => 100,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => false,
            'usage_type' => Service::USAGE_SINGLE,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_PER_APPOINTMENT,
        ]);
    }

    private function qualifiedStaff(array $services): Staff
    {
        $staff = Staff::query()->create([
            'name' => 'Task 17 Staff '.uniqid(),
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

    private function existingAppointment(
        User $client,
        Service $service,
        Staff $staff,
        string $date,
        string $startsAt,
        int $duration,
    ): Appointment {
        return Appointment::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'date' => $date,
            'starts_at' => $startsAt,
            'duration_minutes' => $duration,
            'price' => 100,
            'customer_name' => $client->name,
            'customer_phone' => $client->phone,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
            'reference_code' => 'T17-'.strtoupper(substr(uniqid(), -8)),
        ]);
    }
}
