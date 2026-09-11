<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task14BookingGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_single_appointment_remains_valid_without_a_booking_group(): void
    {
        $client = $this->client('Single');
        $service = $this->service('Single Facial', 60, 80.00, false);

        $appointment = Appointment::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'date' => now()->addDay()->toDateString(),
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 80,
            'customer_name' => $client->name,
            'customer_phone' => $client->phone,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
            'reference_code' => 'T14SINGLE001',
        ]);

        $this->assertNull($appointment->booking_group_id);
        $this->assertNull($appointment->bookingGroup);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'booking_group_id' => null,
        ]);
    }

    public function test_two_treatments_can_share_one_group_without_losing_their_package_identity(): void
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::query()->create([
            'name' => 'Task 14 Admin',
            'email' => 'task14-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = $this->client('Joined');
        $arms = $this->service('Laser Arms', 30, 180.00, true);
        $legs = $this->service('Laser Legs', 45, 250.00, true);

        $armsPackage = $this->package($client, $arms, 180.00);
        $legsPackage = $this->package($client, $legs, 250.00);

        $group = BookingGroup::query()->create([
            'user_id' => $client->id,
            'created_by_user_id' => $admin->id,
        ]);

        $armsAppointment = $this->appointment(
            client: $client,
            service: $arms,
            package: $armsPackage,
            group: $group,
            startsAt: '10:00:00',
            reference: 'T14ARMS0001',
        );

        $legsAppointment = $this->appointment(
            client: $client,
            service: $legs,
            package: $legsPackage,
            group: $group,
            startsAt: '10:30:00',
            reference: 'T14LEGS0001',
        );

        $this->assertSame($group->id, $armsAppointment->booking_group_id);
        $this->assertSame($group->id, $legsAppointment->booking_group_id);
        $this->assertNotSame($armsAppointment->service_id, $legsAppointment->service_id);
        $this->assertNotSame($armsAppointment->service_package_id, $legsAppointment->service_package_id);

        $group->load('appointments');
        $this->assertCount(2, $group->appointments);
        $this->assertSame(
            [$armsAppointment->id, $legsAppointment->id],
            $group->appointments->pluck('id')->all(),
        );

        // The group is only a linking/presentation entity. Ungrouping must not
        // destroy treatment or package-accounting records.
        $group->delete();

        $armsAppointment->refresh();
        $legsAppointment->refresh();

        $this->assertNull($armsAppointment->booking_group_id);
        $this->assertNull($legsAppointment->booking_group_id);
        $this->assertDatabaseHas('appointments', ['id' => $armsAppointment->id]);
        $this->assertDatabaseHas('appointments', ['id' => $legsAppointment->id]);
        $this->assertDatabaseHas('service_packages', ['id' => $armsPackage->id]);
        $this->assertDatabaseHas('service_packages', ['id' => $legsPackage->id]);
    }

    private function client(string $suffix): User
    {
        Role::findOrCreate('client', 'web');

        $client = User::query()->create([
            'name' => "Task 14 {$suffix} Client",
            'email' => 'task14-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return $client;
    }

    private function service(string $name, int $duration, float $price, bool $package): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 14 '.uniqid(),
            'slug' => 'task-14-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-14-service-'.uniqid(),
            'duration_minutes' => $duration,
            'price' => $price,
            'is_active' => true,
            'is_bookable' => true,
            'is_package' => $package,
            'total_sessions' => $package ? 6 : null,
            'usage_type' => $package ? Service::USAGE_SESSION : Service::USAGE_SINGLE,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_ANY_QUALIFIED,
        ]);
    }

    private function package(User $client, Service $service, float $price): ServicePackage
    {
        return ServicePackage::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'snapshot_minimum_interval_days' => 0,
            'snapshot_deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'snapshot_staff_policy' => Service::STAFF_ANY_QUALIFIED,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'price_paid' => 0,
            'price_total' => $price,
            'currency' => 'EUR',
            'remaining_sessions' => 6,
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => now()->toDateString(),
        ]);
    }

    private function appointment(
        User $client,
        Service $service,
        ServicePackage $package,
        BookingGroup $group,
        string $startsAt,
        string $reference,
    ): Appointment {
        return Appointment::query()->create([
            'booking_group_id' => $group->id,
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => now()->addDay()->toDateString(),
            'starts_at' => $startsAt,
            'duration_minutes' => $service->duration_minutes,
            'price' => 0,
            'customer_name' => $client->name,
            'customer_phone' => $client->phone,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
            'reference_code' => $reference,
        ]);
    }
}
