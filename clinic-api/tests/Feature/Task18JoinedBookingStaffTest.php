<?php

namespace Tests\Feature;

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

class Task18JoinedBookingStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_picker_returns_only_staff_qualified_for_every_treatment(): void
    {
        [$admin] = $this->users();
        $arms = $this->service('Laser Arms');
        $legs = $this->service('Laser Legs');

        $armsOnly = $this->staff('Arms Only');
        $common = $this->staff('Common Staff');
        $legsOnly = $this->staff('Legs Only');

        $arms->staff()->attach([$armsOnly->id, $common->id]);
        $legs->staff()->attach([$common->id, $legsOnly->id]);

        Sanctum::actingAs($admin, ['*']);

        $query = http_build_query([
            'service_ids' => [$arms->id, $legs->id],
        ]);

        $response = $this->getJson("/api/v1/admin/booking-groups/staff?{$query}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.staff')
            ->assertJsonPath('data.staff.0.id', $common->id)
            ->assertJsonPath('data.locked_staff_id', null);
    }

    public function test_completed_same_staff_package_locks_joined_visit_to_its_assigned_staff(): void
    {
        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms');
        $legs = $this->service('Laser Legs');
        $first = $this->staff('Assigned Staff');
        $second = $this->staff('Other Qualified Staff');

        $arms->staff()->attach([$first->id, $second->id]);
        $legs->staff()->attach([$first->id, $second->id]);

        $package = $this->package($client, $arms, $first);
        PackageLog::query()->forceCreate([
            'service_package_id' => $package->id,
            'staff_id' => $first->id,
            'usage_type' => Service::USAGE_SESSION,
            'quantity' => 1,
            'session_number' => 1,
            'used_sessions' => 1,
            'used_minutes' => 0,
            'used_at' => now(),
            'occurred_on' => Carbon::today()->toDateString(),
            'source' => PackageLog::SOURCE_MANUAL,
            'created_by_id' => $admin->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $query = http_build_query([
            'service_ids' => [$arms->id, $legs->id],
            'package_ids' => [$package->id],
        ]);

        $response = $this->getJson("/api/v1/admin/booking-groups/staff?{$query}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.staff')
            ->assertJsonPath('data.staff.0.id', $first->id)
            ->assertJsonPath('data.locked_staff_id', $first->id);
    }

    public function test_group_creation_rejects_staff_who_cannot_perform_every_treatment_before_writing_group(): void
    {
        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms');
        $legs = $this->service('Laser Legs');
        $staff = $this->staff('Arms Specialist');
        $arms->staff()->attach($staff->id);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/booking-groups", [
            'date' => Carbon::tomorrow()->toDateString(),
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
            ->assertJsonValidationErrors(['staff_id']);

        $this->assertSame(0, BookingGroup::query()->count());
    }

    public function test_two_hard_same_staff_packages_locked_to_different_people_have_no_v1_staff_owner(): void
    {
        [$admin, $client] = $this->users();
        $arms = $this->service('Laser Arms');
        $legs = $this->service('Laser Legs');
        $first = $this->staff('Staff One');
        $second = $this->staff('Staff Two');

        $arms->staff()->attach([$first->id, $second->id]);
        $legs->staff()->attach([$first->id, $second->id]);

        $armsPackage = $this->package($client, $arms, $first);
        $legsPackage = $this->package($client, $legs, $second);

        foreach ([[$armsPackage, $first], [$legsPackage, $second]] as [$package, $staff]) {
            PackageLog::query()->forceCreate([
                'service_package_id' => $package->id,
                'staff_id' => $staff->id,
                'usage_type' => Service::USAGE_SESSION,
                'quantity' => 1,
                'session_number' => 1,
                'used_sessions' => 1,
                'used_minutes' => 0,
                'used_at' => now(),
                'occurred_on' => Carbon::today()->toDateString(),
                'source' => PackageLog::SOURCE_MANUAL,
                'created_by_id' => $admin->id,
            ]);
        }

        Sanctum::actingAs($admin, ['*']);

        $query = http_build_query([
            'service_ids' => [$arms->id, $legs->id],
            'package_ids' => [$armsPackage->id, $legsPackage->id],
        ]);

        $response = $this->getJson("/api/v1/admin/booking-groups/staff?{$query}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data.staff')
            ->assertJsonPath('data.locked_staff_id', null);

        $this->assertStringContainsString(
            'locked to different staff members',
            (string) $response->json('data.constraint_message'),
        );
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task 18 Admin',
            'email' => 'task18-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task 18 Client',
            'email' => 'task18-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function service(string $name): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task 18 '.uniqid(),
            'slug' => 'task-18-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => 'task-18-service-'.uniqid(),
            'duration_minutes' => 30,
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

    private function staff(string $name): Staff
    {
        return Staff::query()->create([
            'name' => $name.' '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function package(User $client, Service $service, Staff $staff): ServicePackage
    {
        return ServicePackage::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'assigned_staff_id' => $staff->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'remaining_sessions' => 5,
            'snapshot_usage_type' => Service::USAGE_SESSION,
            'snapshot_minimum_interval_days' => 0,
            'snapshot_deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'snapshot_staff_policy' => Service::STAFF_SAME,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'price_total' => 450,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => Carbon::today()->subMonth()->toDateString(),
        ]);
    }
}
