<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingGroup;
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

class Task20JoinedBookingCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_exposes_joined_visit_as_one_visual_group_with_treatments_and_session_numbers(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');

        [$admin, $client] = $this->users();
        $arms = $this->packageService('Laser Arms', 30);
        $legs = $this->packageService('Laser Legs', 45);
        $staff = $this->staff([$arms, $legs]);
        $armsPackage = $this->package($client, $arms);
        $legsPackage = $this->package($client, $legs);
        $group = BookingGroup::query()->create([
            'user_id' => $client->id,
            'created_by_user_id' => $admin->id,
        ]);

        $armsAppointment = $this->appointment($group, $client, $arms, $armsPackage, $staff, '10:00');
        $legsAppointment = $this->appointment($group, $client, $legs, $legsPackage, $staff, '10:30');

        Sanctum::actingAs($admin, ['*']);

        $date = Carbon::today()->toDateString();
        $response = $this->getJson("/api/v1/admin/appointments/calendar?from={$date}&to={$date}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.booking_group.id', $group->id)
            ->assertJsonPath('items.0.booking_group.primary_appointment_id', $armsAppointment->id)
            ->assertJsonPath('items.0.booking_group.treatment_count', 2)
            ->assertJsonPath('items.0.booking_group.time', '10:00')
            ->assertJsonPath('items.0.booking_group.end_time', '11:15')
            ->assertJsonPath('items.0.booking_group.total_duration_minutes', 75)
            ->assertJsonPath('items.0.booking_group.staff.id', $staff->id)
            ->assertJsonPath('items.0.booking_group.treatments.0.appointment_id', $armsAppointment->id)
            ->assertJsonPath('items.0.booking_group.treatments.0.service.name', 'Laser Arms')
            ->assertJsonPath('items.0.booking_group.treatments.0.package_session.session_number', 1)
            ->assertJsonPath('items.0.booking_group.treatments.0.package_session.total_sessions', 6)
            ->assertJsonPath('items.0.booking_group.treatments.1.appointment_id', $legsAppointment->id)
            ->assertJsonPath('items.0.booking_group.treatments.1.service.name', 'Laser Legs')
            ->assertJsonPath('items.0.booking_group.treatments.1.package_session.session_number', 1)
            ->assertJsonPath('items.0.booking_group.treatments.1.package_session.total_sessions', 6);

        // Both underlying appointments remain independently accessible for
        // correction/audit even though the calendar UI collapses them visually.
        $this->assertDatabaseHas('appointments', ['id' => $armsAppointment->id, 'booking_group_id' => $group->id]);
        $this->assertDatabaseHas('appointments', ['id' => $legsAppointment->id, 'booking_group_id' => $group->id]);
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
            'name' => 'Task 20 Admin',
            'email' => 'task20-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 20 Client',
            'email' => 'task20-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function packageService(string $name, int $duration): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 20 '.uniqid(),
            'slug' => 'task-20-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-20-service-'.uniqid(),
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
            'name' => 'Task 20 Staff '.uniqid(),
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
            'reference_code' => 'T20-'.strtoupper(uniqid()),
            'date' => Carbon::today()->toDateString(),
            'starts_at' => $startsAt,
            'duration_minutes' => $service->duration_minutes,
            'price' => 450,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
        ]);
    }
}
