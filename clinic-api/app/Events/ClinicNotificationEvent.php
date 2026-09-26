<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Domain event only: no email, push or database notification is sent here. */
final class ClinicNotificationEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly int $appointmentId,
        public readonly ?int $bookingGroupId,
        public readonly ?int $clientId,
        public readonly ?int $staffId,
        public readonly ?int $previousStaffId,
        public readonly ?string $oldStatus,
        public readonly ?string $newStatus,
        public readonly string $origin,
        public readonly array $changes = [],
    ) {}
}
