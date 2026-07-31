<?php

namespace Tests\Feature;

use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\AppointmentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseTwoAppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_create_a_future_single_appointment_through_the_unified_endpoint(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('single');
        $staff = $this->createQualifiedStaff($service);
        $date = Carbon::tomorrow()->toDateString();
        $this->createSchedule($staff, $date);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'single',
            'service_id' => $service->id,
            'date' => $date,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'notes' => 'Future single appointment',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.appointment.status', 'confirmed')
            ->assertJsonPath('data.appointment.source', 'admin_booking')
            ->assertJsonPath('data.package', null);

        $this->assertDatabaseHas('appointments', [
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_package_id' => null,
            'staff_id' => $staff->id,
            'status' => 'confirmed',
            'source' => 'admin_booking',
        ]);
    }

    public function test_past_completed_package_visit_is_created_and_deducted_in_one_request(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session', minimumIntervalDays: 28, staffPolicy: 'same_staff');
        $staff = $this->createQualifiedStaff($service);
        $package = $this->createPackage($client, $service, remainingSessions: 6);
        $package->forceFill([
            'snapshot_staff_policy' => $service->staff_policy,
        ])->save();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'existing_package',
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => Carbon::yesterday()->toDateString(),
            'staff_id' => $staff->id,
            'status' => 'completed',
            'notes' => 'Imported completed session',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.appointment.status', 'completed')
            ->assertJsonPath('data.appointment.source', 'manual_import')
            ->assertJsonPath('data.package.remaining_sessions', 5);

        $package->refresh();
        $this->assertSame(5, $package->remaining_sessions);
        $this->assertSame($staff->id, $package->assigned_staff_id);

        $appointmentId = $response->json('data.appointment.id');

        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $package->id,
            'appointment_id' => $appointmentId,
            'usage_type' => 'session',
            'quantity' => 1,
            'source' => 'imported',
            'created_by_id' => $admin->id,
            'voided_at' => null,
        ]);
    }

    public function test_admin_can_import_a_completed_session_before_the_existing_package_start_date(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session');
        $staff = $this->createQualifiedStaff($service);
        $historicalDate = Carbon::now()->subDays(20)->toDateString();

        $package = $this->createPackage($client, $service, remainingSessions: 6);
        $package->forceFill([
            'starts_on' => Carbon::today()->toDateString(),
        ])->save();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'existing_package',
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => $historicalDate,
            'staff_id' => $staff->id,
            'status' => 'completed',
            'notes' => 'Historical session entered after the package was added to the app.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.appointment.source', 'manual_import')
            ->assertJsonPath('data.package.remaining_sessions', 5)
            ->assertJsonFragment([
                'Historical appointment is earlier than the package start date. It was saved as an admin historical import.',
            ]);

        $appointmentId = $response->json('data.appointment.id');

        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $package->id,
            'appointment_id' => $appointmentId,
            'source' => PackageLog::SOURCE_IMPORTED,
            'quantity' => 1,
            'voided_at' => null,
        ]);

        $this->assertDatabaseHas('service_packages', [
            'id' => $package->id,
            'remaining_sessions' => 5,
        ]);

        $updatedPackage = $package->fresh();

        $this->assertSame(
            Carbon::today()->toDateString(),
            $updatedPackage->starts_on?->toDateString(),
        );
    }

    public function test_new_package_and_completed_appointment_roll_back_together_when_completion_fails(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session');
        $staff = $this->createQualifiedStaff($service);

        $this->mock(AppointmentCompletionService::class, function ($mock) {
            $mock->shouldReceive('complete')
                ->once()
                ->andThrow(new RuntimeException('Forced completion failure'));
        });

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'new_package',
            'service_id' => $service->id,
            'date' => Carbon::yesterday()->toDateString(),
            'staff_id' => $staff->id,
            'status' => 'completed',
            'package' => [
                'price_total' => 300,
                'currency' => 'EUR',
                'starts_on' => Carbon::now()->subMonth()->toDateString(),
            ],
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('service_packages', [
            'user_id' => $client->id,
            'service_id' => $service->id,
        ]);
        $this->assertDatabaseMissing('appointments', [
            'user_id' => $client->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_new_package_snapshots_duration_and_uses_the_historical_date_as_default_start(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session');
        $staff = $this->createQualifiedStaff($service);
        $historicalDate = Carbon::now()->subDays(10)->toDateString();

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            'purchase_type' => 'new_package',
            'service_id' => $service->id,
            'date' => $historicalDate,
            'staff_id' => $staff->id,
            'status' => 'cancelled',
            'package' => [
                'price_total' => 300,
                'currency' => 'EUR',
            ],
        ])->assertCreated();

        $packageId = $response->json('data.package.id');

        $this->assertDatabaseHas('service_packages', [
            'id' => $packageId,
            'snapshot_duration_minutes' => 60,
        ]);

        $this->assertSame(
            $historicalDate,
            \App\Models\ServicePackage::query()
                ->findOrFail($packageId)
                ->starts_on
                ->toDateString(),
        );
    }

    public function test_interval_is_blocked_for_scheduled_booking_unless_admin_supplies_an_override_reason(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session', minimumIntervalDays: 28);
        $staff = $this->createQualifiedStaff($service);
        $package = $this->createPackage($client, $service, remainingSessions: 5);
        $lastUse = Carbon::yesterday();
        $bookingDate = Carbon::now()->addDays(10)->toDateString();
        $this->createSchedule($staff, $bookingDate);
        $this->createSessionUsage($package, $staff, $lastUse->toDateString(), 1);

        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'purchase_type' => 'existing_package',
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => $bookingDate,
            'starts_at' => '10:00',
            'staff_id' => $staff->id,
            'status' => 'confirmed',
        ];

        $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            ...$payload,
            'interval_override' => true,
            'interval_override_reason' => 'Doctor-approved earlier treatment date.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.appointment.status', 'confirmed');

        $package->refresh();
        $this->assertSame(5, $package->remaining_sessions);
    }

    public function test_same_staff_package_requires_override_after_first_completed_session(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        [$admin, $client] = $this->createUsers();
        $service = $this->createService('session', minimumIntervalDays: 0, staffPolicy: 'same_staff');
        $firstStaff = $this->createQualifiedStaff($service, 'First Staff');
        $secondStaff = $this->createQualifiedStaff($service, 'Second Staff');
        $package = $this->createPackage(
            $client,
            $service,
            remainingSessions: 5,
            assignedStaffId: $firstStaff->id,
            staffPolicy: 'same_staff',
        );
        $this->createSessionUsage($package, $firstStaff, Carbon::yesterday()->toDateString(), 1);

        $bookingDate = Carbon::tomorrow()->toDateString();
        $this->createSchedule($secondStaff, $bookingDate);
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'purchase_type' => 'existing_package',
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'date' => $bookingDate,
            'starts_at' => '10:00',
            'staff_id' => $secondStaff->id,
            'status' => 'confirmed',
        ];

        $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['staff_id']);

        $this->postJson("/api/v1/admin/clients/{$client->id}/appointments", [
            ...$payload,
            'staff_override' => true,
            'staff_override_reason' => 'Assigned staff is on extended leave.',
        ])->assertCreated();

        $this->assertDatabaseHas('service_packages', [
            'id' => $package->id,
            'assigned_staff_id' => $secondStaff->id,
        ]);
    }

    private function createUsers(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Phase Two Admin',
            'email' => 'phase2-admin@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Phase Two Client',
            'email' => 'phase2-client@example.com',
            'password' => 'password',
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function createService(
        string $usageType,
        int $minimumIntervalDays = 0,
        string $staffPolicy = 'any_qualified_staff',
    ): Service {
        $category = ServiceCategory::query()->create([
            'name' => 'Phase Two Category ' . uniqid(),
            'slug' => 'phase-two-' . uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => 'Phase Two ' . ucfirst($usageType) . ' ' . uniqid(),
            'duration_minutes' => 60,
            'price' => 100,
            'is_active' => true,
            'is_bookable' => $usageType !== 'minutes',
            'is_package' => $usageType !== 'single',
            'total_sessions' => $usageType === 'session' ? 6 : null,
            'total_minutes' => $usageType === 'minutes' ? 50 : null,
            'usage_type' => $usageType,
            'minimum_interval_days' => $minimumIntervalDays,
            'deduction_method' => $usageType === 'minutes' ? 'manual' : 'automatic_on_completion',
            'staff_policy' => $usageType === 'single' ? 'per_appointment' : $staffPolicy,
        ]);
    }

    private function createQualifiedStaff(Service $service, string $name = 'Phase Two Staff'): Staff
    {
        $staff = Staff::query()->create([
            'name' => $name . ' ' . uniqid(),
            'is_active' => true,
        ]);
        $service->staff()->attach($staff->id);

        return $staff;
    }

    private function createSchedule(Staff $staff, string $date): void
    {
        StaffSchedule::query()->create([
            'staff_id' => $staff->id,
            'weekday' => Carbon::parse($date)->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
        ]);
    }

    private function createPackage(
        User $client,
        Service $service,
        int $remainingSessions,
        ?int $assignedStaffId = null,
        string $staffPolicy = 'any_qualified_staff',
    ): ServicePackage {
        return ServicePackage::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => 6,
            'snapshot_total_minutes' => null,
            'remaining_sessions' => $remainingSessions,
            'remaining_minutes' => null,
            'snapshot_usage_type' => 'session',
            'snapshot_minimum_interval_days' => (int) $service->minimum_interval_days,
            'snapshot_deduction_method' => 'automatic_on_completion',
            'snapshot_staff_policy' => $staffPolicy,
            'assigned_staff_id' => $assignedStaffId,
            'price_total' => 300,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => Carbon::now()->subMonth()->toDateString(),
        ]);
    }

    private function createSessionUsage(
        ServicePackage $package,
        Staff $staff,
        string $occurredOn,
        int $sessionNumber,
    ): PackageLog {
        return PackageLog::query()->forceCreate([
            'service_package_id' => $package->id,
            'staff_id' => $staff->id,
            'appointment_id' => null,
            'used_sessions' => 1,
            'used_minutes' => null,
            'used_at' => Carbon::parse($occurredOn),
            'usage_type' => 'session',
            'quantity' => 1,
            'session_number' => $sessionNumber,
            'occurred_on' => $occurredOn,
            'source' => 'imported',
            'note' => 'Existing completed session',
        ]);
    }
}
