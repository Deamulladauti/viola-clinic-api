<?php

namespace Tests\Unit;

use App\Events\ClinicNotificationEvent;
use App\Services\ClinicNotificationEvents;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClinicNotificationEventContractTest extends TestCase
{
    public function test_expected_event_types_are_defined(): void
    {
        $this->assertSame([
            'appointment.created', 'appointment.rescheduled', 'appointment.cancelled',
            'appointment.staff_reassigned', 'appointment.reminder_due',
        ], ClinicNotificationEvents::TYPES);
    }

    public function test_payload_retains_group_and_before_after_context(): void
    {
        $event = new ClinicNotificationEvent(
            ClinicNotificationEvents::RESCHEDULED, 42, 9, 12, 3, 2,
            'pending', 'confirmed', 'admin',
            ['date' => ['from' => '2026-10-01', 'to' => '2026-10-02']],
        );
        $this->assertSame(9, $event->bookingGroupId);
        $this->assertSame(2, $event->previousStaffId);
        $this->assertSame('2026-10-01', $event->changes['date']['from']);
    }
}
