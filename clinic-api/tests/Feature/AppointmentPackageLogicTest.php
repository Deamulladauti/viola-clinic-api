<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\PackageLog;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\User;
use App\Services\AppointmentCompletionService;
use App\Services\PackageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentPackageLogicTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_completion_deducts_exactly_once(): void
    {
        [$user, $staff, $service, $package] = $this->sessionPackageFixture();
        $appointment = $this->appointment($user, $staff, $service, $package, '2026-07-12');

        $completion = app(AppointmentCompletionService::class);
        $completion->complete($appointment, $user->id);
        $completion->complete($appointment->refresh(), $user->id);

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->refresh()->status);
        $this->assertSame(5, $package->refresh()->remaining_sessions);
        $this->assertDatabaseCount('package_logs', 1);
        $this->assertDatabaseHas('package_logs', [
            'service_package_id' => $package->id,
            'appointment_id' => $appointment->id,
            'active_appointment_id' => $appointment->id,
            'usage_type' => Service::USAGE_SESSION,
            'quantity' => 1,
            'session_number' => 1,
            'voided_at' => null,
        ]);
    }

    public function test_session_appointment_cannot_complete_without_exact_package(): void
    {
        [$user, $staff, $service] = $this->sessionPackageFixture();
        $appointment = $this->appointment($user, $staff, $service, null, '2026-07-12');

        try {
            app(AppointmentCompletionService::class)->complete($appointment, $user->id);
            $this->fail('Expected package-link validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_package_id', $exception->errors());
        }

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->refresh()->status);
        $this->assertDatabaseCount('package_logs', 0);
    }

    public function test_single_appointment_completion_does_not_touch_packages(): void
    {
        $user = $this->user();
        $staff = $this->staff();
        $service = $this->service(Service::USAGE_SINGLE);
        $appointment = $this->appointment($user, $staff, $service, null, '2026-07-12');

        app(AppointmentCompletionService::class)->complete($appointment, $user->id);

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->refresh()->status);
        $this->assertDatabaseCount('package_logs', 0);
    }

    public function test_minimum_interval_blocks_an_early_session(): void
    {
        [$user, $staff, $service, $package] = $this->sessionPackageFixture(minimumIntervalDays: 28);
        $first = $this->appointment($user, $staff, $service, $package, '2026-07-01');
        $second = $this->appointment($user, $staff, $service, $package, '2026-07-15');

        $completion = app(AppointmentCompletionService::class);
        $completion->complete($first, $user->id);

        $this->expectException(ValidationException::class);
        $completion->complete($second, $user->id);
    }

    public function test_manual_quantity_usage_is_minutes_only_and_cannot_exceed_balance(): void
    {
        $user = $this->user();
        $service = $this->service(Service::USAGE_MINUTES, totalMinutes: 100);
        $package = $this->package($user, $service, remainingMinutes: 100);

        $usage = app(PackageUsageService::class);
        $log = $usage->recordManualQuantityUsage(
            package: $package,
            quantity: 15,
            occurredOn: '2026-07-12',
            staffId: null,
            actorUserId: $user->id,
        );

        $this->assertSame(85, $package->refresh()->remaining_minutes);
        $this->assertSame(Service::USAGE_MINUTES, $log->usage_type);
        $this->assertSame(15, $log->quantity);

        $this->expectException(ValidationException::class);
        $usage->recordManualQuantityUsage(
            package: $package->refresh(),
            quantity: 86,
            occurredOn: '2026-07-12',
            staffId: null,
            actorUserId: $user->id,
        );
    }

    public function test_reversing_completion_restores_balance_and_voids_usage(): void
    {
        [$user, $staff, $service, $package] = $this->sessionPackageFixture();
        $appointment = $this->appointment($user, $staff, $service, $package, '2026-07-12');
        $completion = app(AppointmentCompletionService::class);

        $completion->complete($appointment, $user->id);
        $completion->reverseCompletion(
            appointment: $appointment->refresh(),
            targetStatus: Appointment::STATUS_CANCELLED,
            actorUserId: $user->id,
            reason: 'Completed by mistake',
        );

        $this->assertSame(6, $package->refresh()->remaining_sessions);
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->refresh()->status);

        $log = PackageLog::withTrashed()->firstOrFail();
        $this->assertNotNull($log->voided_at);
        $this->assertNull($log->active_appointment_id);
        $this->assertSame('Completed by mistake', $log->void_reason);
    }

    public function test_same_staff_package_locks_after_first_completed_session(): void
    {
        [$user, $firstStaff, $service, $package] = $this->sessionPackageFixture(
            staffPolicy: Service::STAFF_SAME,
        );
        $secondStaff = $this->staff();
        $first = $this->appointment($user, $firstStaff, $service, $package, '2026-07-01');
        $second = $this->appointment($user, $secondStaff, $service, $package, '2026-08-01');

        $completion = app(AppointmentCompletionService::class);
        $completion->complete($first, $user->id);

        $this->assertSame($firstStaff->id, $package->refresh()->assigned_staff_id);
        $this->expectException(ValidationException::class);
        $completion->complete($second, $user->id);
    }

    private function sessionPackageFixture(
        int $minimumIntervalDays = 0,
        string $staffPolicy = Service::STAFF_ANY_QUALIFIED,
    ): array {
        $user = $this->user();
        $staff = $this->staff();
        $service = $this->service(
            usageType: Service::USAGE_SESSION,
            totalSessions: 6,
            minimumIntervalDays: $minimumIntervalDays,
            staffPolicy: $staffPolicy,
        );
        $package = $this->package($user, $service, remainingSessions: 6);

        return [$user, $staff, $service, $package];
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Test Client '.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function staff(): Staff
    {
        return Staff::create([
            'name' => 'Test Staff '.uniqid(),
            'email' => uniqid().'@staff.test',
            'is_active' => true,
        ]);
    }

    private function service(
        string $usageType,
        ?int $totalSessions = null,
        ?int $totalMinutes = null,
        int $minimumIntervalDays = 0,
        string $staffPolicy = Service::STAFF_PER_APPOINTMENT,
    ): Service {
        $category = ServiceCategory::create([
            'name' => 'Category '.uniqid(),
            'slug' => 'category-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::create([
            'service_category_id' => $category->id,
            'name' => 'Service '.uniqid(),
            'slug' => 'service-'.uniqid(),
            'duration_minutes' => 60,
            'price' => 100,
            'is_active' => true,
            'is_bookable' => $usageType !== Service::USAGE_MINUTES,
            'is_package' => $usageType !== Service::USAGE_SINGLE,
            'total_sessions' => $totalSessions,
            'total_minutes' => $totalMinutes,
            'usage_type' => $usageType,
            'minimum_interval_days' => $minimumIntervalDays,
            'deduction_method' => $usageType === Service::USAGE_MINUTES
                ? Service::DEDUCTION_MANUAL
                : Service::DEDUCTION_AUTOMATIC,
            'staff_policy' => $staffPolicy,
        ]);
    }

    private function package(
        User $user,
        Service $service,
        ?int $remainingSessions = null,
        ?int $remainingMinutes = null,
    ): ServicePackage {
        return ServicePackage::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'snapshot_total_sessions' => $service->total_sessions,
            'snapshot_total_minutes' => $service->total_minutes,
            'snapshot_usage_type' => $service->usage_type,
            'snapshot_minimum_interval_days' => $service->minimum_interval_days,
            'snapshot_deduction_method' => $service->deduction_method,
            'snapshot_staff_policy' => $service->staff_policy,
            'snapshot_duration_minutes' => $service->duration_minutes,
            'remaining_sessions' => $remainingSessions,
            'remaining_minutes' => $remainingMinutes,
            'price_total' => $service->price,
            'price_paid' => 0,
            'currency' => 'EUR',
            'status' => ServicePackage::STATUS_ACTIVE,
            'starts_on' => '2026-01-01',
        ]);
    }

    private function appointment(
        User $user,
        Staff $staff,
        Service $service,
        ?ServicePackage $package,
        string $date,
    ): Appointment {
        return Appointment::create([
            'service_id' => $service->id,
            'service_package_id' => $package?->id,
            'staff_id' => $staff->id,
            'user_id' => $user->id,
            'date' => $date,
            'starts_at' => '10:00:00',
            'duration_minutes' => 60,
            'price' => $service->price,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_ADMIN_BOOKING,
            'reference_code' => strtoupper(substr(uniqid(), 0, 12)),
        ]);
    }
}
