<?php

namespace Tests\Unit;

use App\Notifications\AdminAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;
use PHPUnit\Framework\TestCase;

class AdminAppointmentLifecycleTest extends TestCase
{
    public function test_admin_notification_has_visit_reference_and_database_channel(): void
    {
        $notification = new AdminAppointmentLifecycle(ClinicNotificationEvents::CREATED, 4, 9, 'Test Client', 'Legs + Arms', '2026-10-01', '11:30:00');
        $this->assertSame(['database'], $notification->via((object) []));
        foreach (['en', 'sq', 'mk'] as $lang) {
            $data = $notification->toArray((object) ['preferred_language' => $lang]);
            $this->assertSame(4, $data['appointment_id']);
            $this->assertSame(9, $data['booking_group_id']);
            $this->assertStringContainsString('Legs + Arms', $data['body']);
        }
    }
}
