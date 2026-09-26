<?php

namespace Tests\Unit;

use App\Notifications\StaffAppointmentLifecycle;
use App\Services\ClinicNotificationEvents;
use PHPUnit\Framework\TestCase;

class StaffAppointmentLifecycleTest extends TestCase
{
    public function test_reassignment_has_distinct_messages_for_old_and_new_staff(): void
    {
        $user = (object) ['preferred_language' => 'en'];
        $old = new StaffAppointmentLifecycle(ClinicNotificationEvents::STAFF_REASSIGNED, 'removed', 5, null, 'Laser Legs', '2026-10-02', '10:00:00');
        $new = new StaffAppointmentLifecycle(ClinicNotificationEvents::STAFF_REASSIGNED, 'assigned', 5, null, 'Laser Legs', '2026-10-02', '10:00:00');
        $this->assertSame(['database'], $new->via($user));
        $this->assertSame(5, $old->toArray($user)['appointment_id']);
        $this->assertNotSame($old->toArray($user)['title'], $new->toArray($user)['title']);
    }

    public function test_languages_and_group_id_are_retained(): void
    {
        $notification = new StaffAppointmentLifecycle(ClinicNotificationEvents::CREATED, 'current', 5, 12, 'Legs + Arms', '2026-10-02', '10:00:00');
        foreach (['en', 'sq', 'mk'] as $language) {
            $data = $notification->toArray((object) ['preferred_language' => $language]);
            $this->assertSame(12, $data['booking_group_id']);
            $this->assertStringContainsString('Legs + Arms', $data['body']);
        }
    }
}
