<?php

namespace Tests\Feature;

use App\Models\Appointment;
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

class Task7AdminAppointmentEditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_edit_a_future_booking_and_the_change_is_audited(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        [$admin, $client] = $this->users();
        $firstService = $this->service('First treatment', 60, 100);
        $secondService = $this->service('Second treatment', 45, 80);
        $staff = $this->staff('Editor Staff');
        $firstService->staff()->attach($staff->id);
        $secondService->staff()->attach($staff->id);

        $oldDate = Carbon::tomorrow()->toDateString();
        $newDate = Carbon::now()->addDays(2)->toDateString();
        $this->schedule($staff, $oldDate);
        $this->schedule($staff, $newDate);

        $appointment = Appointment::query()->create([
            'user_id' => $client->id,
            'service_id' => $firstService->id,
            'staff_id' => $staff->id,
            'date' => $oldDate,
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 100,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
            'reference_code' => 'TASK7EDIT01',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}", [
            'service_id' => $secondService->id,
            'service_package_id' => null,
            'staff_id' => $staff->id,
            'date' => $newDate,
            'starts_at' => '11:15',
            'notes' => 'Corrected future booking.',
        ])
            ->assertOk()
            ->assertJsonPath('data.appointment.service_id', $secondService->id)
            ->assertJsonPath('data.appointment.date', $newDate);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'service_id' => $secondService->id,
            'starts_at' => '11:15:00',
            'duration_minutes' => 45,
            'price' => 80,
            'notes' => 'Corrected future booking.',
        ]);

        // SQLite stores Laravel date casts as midnight datetimes internally,
        // while MySQL DATE columns return the calendar date. Assert through
        // the model cast so this test behaves consistently on both databases.
        $updatedAppointment = $appointment->fresh();
        $this->assertSame($newDate, $updatedAppointment->date->toDateString());

        $this->assertDatabaseHas('appointment_logs', [
            'appointment_id' => $appointment->id,
            'user_id' => $admin->id,
            'action' => 'appointment_edited',
        ]);
    }

    public function test_completed_visit_must_be_reopened_with_a_reason_before_it_can_be_edited(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        [$admin, $client] = $this->users();
        $service = $this->service('Completed treatment', 60, 100);
        $staff = $this->staff('Completed Staff');
        $service->staff()->attach($staff->id);

        $correctedPastDate = Carbon::now()->subDays(2)->toDateString();

        $appointment = Appointment::query()->create([
            'user_id' => $client->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'date' => Carbon::yesterday()->toDateString(),
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => 100,
            'customer_name' => $client->name,
            'customer_email' => $client->email,
            'status' => Appointment::STATUS_COMPLETED,
            'source' => Appointment::SOURCE_MANUAL_IMPORT,
            'reference_code' => 'TASK7EDIT02',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}", [
            'date' => $correctedPastDate,
            'starts_at' => '11:00',
            'staff_id' => $staff->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment']);

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertUnprocessable();

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_CONFIRMED,
            'reason' => 'The wrong visit was marked completed.',
        ])->assertOk();

        $this->patchJson("/api/v1/admin/appointments/{$appointment->id}", [
            'date' => $correctedPastDate,
            'starts_at' => '11:00',
            'staff_id' => $staff->id,
        ])->assertOk();

        $this->assertDatabaseHas('appointment_logs', [
            'appointment_id' => $appointment->id,
            'action' => 'completion_reversed',
        ]);
    }

    private function users(): array
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('client', 'web');

        $admin = User::query()->create([
            'name' => 'Task Seven Admin',
            'email' => 'task7-admin@example.com',
            'password' => 'password',
        ]);
        $admin->assignRole('admin');

        $client = User::query()->create([
            'name' => 'Task Seven Client',
            'email' => 'task7-client@example.com',
            'password' => 'password',
        ]);
        $client->assignRole('client');

        return [$admin, $client];
    }

    private function service(string $name, int $duration, float $price): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => 'Task Seven ' . uniqid(),
            'slug' => 'task-seven-' . uniqid(),
            'is_active' => true,
        ]);

        return Service::query()->create([
            'service_category_id' => $category->id,
            'name' => $name . ' ' . uniqid(),
            'duration_minutes' => $duration,
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

    private function staff(string $name): Staff
    {
        return Staff::query()->create([
            'name' => $name . ' ' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function schedule(Staff $staff, string $date): void
    {
        StaffSchedule::query()->firstOrCreate([
            'staff_id' => $staff->id,
            'weekday' => Carbon::parse($date)->dayOfWeek,
        ], [
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
        ]);
    }
}