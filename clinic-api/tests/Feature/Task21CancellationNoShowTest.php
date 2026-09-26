<?php

namespace Tests\Feature;

use App\Models\Appointment;
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

class Task21CancellationNoShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_appointment_does_not_count_as_a_missed_session_or_consume_package_usage(): void
    {
        [$admin, $appointment, $package] = $this->packageAppointment();
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CANCELLED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);

        $this->assertSame(6, (int) $package->refresh()->remaining_sessions);
        $this->assertSame(0, PackageLog::query()->where('service_package_id', $package->id)->count());

        $this->assertDatabaseHas('appointment_logs', [
            'appointment_id' => $appointment->id,
            'action' => 'status_changed',
        ]);
    }

    public function test_no_show_is_the_only_missed_session_event_for_v1_but_does_not_deduct_a_session_yet(): void
    {
        [$admin, $appointment, $package] = $this->packageAppointment();
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_NO_SHOW,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_NO_SHOW);

        // Task 21 only defines the event. Task 22-24 will configure/count/apply
        // the penalty. A no-show must not consume a package session by itself.
        $this->assertSame(6, (int) $package->refresh()->remaining_sessions);
        $this->assertSame(0, PackageLog::query()->where('service_package_id', $package->id)->count());

        $this->assertDatabaseHas('appointment_logs', [
            'appointment_id' => $appointment->id,
            'action' => 'status_changed',
        ]);
    }

    public function test_cancelled_and_no_show_remain_separate_final_statuses(): void
    {
        [$admin, $cancelledAppointment] = $this->plainAppointment();
        [, $noShowAppointment] = $this->plainAppointment($admin);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$cancelledAppointment->id}/status", [
            'status' => Appointment::STATUS_CANCELLED,
        ])->assertOk();

        $this->patchJson("/api/v1/admin/appointments/{$noShowAppointment->id}/status", [
            'status' => Appointment::STATUS_NO_SHOW,
        ])->assertOk();

        $this->assertSame(Appointment::STATUS_CANCELLED, $cancelledAppointment->refresh()->status);
        $this->assertSame(Appointment::STATUS_NO_SHOW, $noShowAppointment->refresh()->status);
    }

    private function packageAppointment(): array
    {
        [$admin, $appointment, $client, $service, $staff] = $this->plainAppointment();

        $service->forceFill([
            'is_package' => true,
            'total_sessions' => 6,
            'usage_type' => Service::USAGE_SESSION,
            'minimum_interval_days' => 0,
            'deduction_method' => Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => Service::STAFF_ANY_QUALIFIED,
        ])->save();

        $package = ServicePackage::query()->forceCreate([
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

        $appointment->forceFill([
            'service_package_id' => $package->id,
            'price' => 450,
        ])->save();

        return [$admin, $appointment, $package];
    }

    /**
     * @return array{0: User, 1: Appointment, 2: User, 3: Service, 4: Staff}
     */
    private function plainAppointment(?User $existingAdmin = null): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = $existingAdmin ?: User::query()->create([
            'name' => 'Task 21 Admin',
            'email' => 'task21-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        if (!$admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $client = User::query()->create([
            'name' => 'Task 21 Client '.uniqid(),
            'email' => 'task21-client-'.uniqid().'@example.com',
            'phone' => '+38970'.random_int(100000, 999999),
            'password' => null,
        ]);
        $client->assignRole('client');

        $category = ServiceCategory::query()->create([
            'name' => 'Task 21 '.uniqid(),
            'slug' => 'task-21-'.uniqid(),
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'service_category_id' => $category->id,
            'name' => 'Task 21 Treatment '.uniqid(),
            'slug' => 'task-21-service-'.uniqid(),
            'duration_minutes' => 45,
            'price' => 100,
            'is_active' => true,
            'is_bookable' => true,
        ]);

        $staff = Staff::query()->create([
            'name' => 'Task 21 Staff '.uniqid(),
            'is_active' => true,
        ]);
        $service->staff()->attach($staff->id);

        $appointment = Appointment::query()->forceCreate([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'customer_phone' => $client->phone,
            'reference_code' => 'T21-'.strtoupper(uniqid()),
            'date' => Carbon::today()->addDay()->toDateString(),
            'starts_at' => '10:00',
            'duration_minutes' => 45,
            'price' => 100,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
        ]);

        return [$admin, $appointment, $client, $service, $staff];
    }
}
