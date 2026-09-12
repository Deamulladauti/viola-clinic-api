<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookingGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminBookingGroupService
{
    public function __construct(
        private readonly AdminClientAppointmentService $appointmentService,
        private readonly BookingGroupAvailabilityService $availabilityService,
    ) {
    }

    /**
     * Create every treatment for one joined clinic visit atomically.
     *
     * Each treatment still goes through the existing canonical appointment
     * creation service, so package ownership, sale pricing, staff qualification,
     * interval rules, historical completion, and package usage all retain their
     * existing behavior. The outer transaction guarantees that a later failure
     * rolls back the group, all earlier appointments, and any package created by
     * an earlier treatment in the same request.
     *
     * @return array{
     *   group: BookingGroup,
     *   appointments: array<int, Appointment>,
     *   packages: array<int, mixed>,
     *   warnings: array<int, string>,
     *   total_duration_minutes: int
     * }
     */
    public function create(User $client, array $data, User $admin): array
    {
        return DB::transaction(function () use ($client, $data, $admin) {
            $treatments = array_values((array) ($data['treatments'] ?? []));

            if (count($treatments) < 2) {
                throw ValidationException::withMessages([
                    'treatments' => 'A joined booking requires at least two treatments.',
                ]);
            }

            $timezone = config('clinic.timezone', config('app.timezone'));
            $selectedDate = Carbon::createFromFormat('Y-m-d', (string) $data['date'], $timezone)->startOfDay();
            $today = Carbon::today($timezone);
            $requestedStatus = str_replace('-', '_', (string) ($data['status'] ?? 'confirmed'));
            $isHistorical = $selectedDate->lt($today)
                || ($selectedDate->lte($today) && in_array($requestedStatus, [
                    Appointment::STATUS_COMPLETED,
                    Appointment::STATUS_CANCELLED,
                    Appointment::STATUS_NO_SHOW,
                ], true));

            // Task 17: before writing the booking group, validate the entire
            // joined visit as one continuous block. This catches a later
            // conflict even when the first treatment would fit by itself.
            if (!$isHistorical) {
                if (blank($data['starts_at'] ?? null)) {
                    throw ValidationException::withMessages([
                        'starts_at' => 'Choose a start time for the joined booking.',
                    ]);
                }

                $this->availabilityService->validateBlock(
                    array_map(
                        fn (array $treatment) => (int) $treatment['service_id'],
                        $treatments,
                    ),
                    (int) $data['staff_id'],
                    (string) $data['date'],
                    (string) $data['starts_at'],
                );
            }

            $group = BookingGroup::query()->create([
                'user_id' => $client->id,
                'created_by_user_id' => $admin->id,
            ]);

            $currentStart = filled($data['starts_at'] ?? null)
                ? Carbon::createFromFormat('H:i', (string) $data['starts_at'], $timezone)
                : null;

            $appointments = [];
            $packages = [];
            $warnings = [];
            $totalDuration = 0;

            foreach ($treatments as $index => $treatment) {
                $treatment = (array) $treatment;

                $payload = array_merge($treatment, [
                    'date' => (string) $data['date'],
                    'starts_at' => $currentStart?->format('H:i'),
                    'staff_id' => (int) $data['staff_id'],
                    'status' => $data['status'] ?? 'confirmed',
                    'notes' => $treatment['notes'] ?? ($data['notes'] ?? null),
                ]);

                unset($payload['key']);

                try {
                    $created = $this->appointmentService->create($client, $payload, $admin);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["treatments.{$index}.{$field}"] = $messages;
                    }

                    throw ValidationException::withMessages($errors ?: [
                        "treatments.{$index}" => 'This treatment could not be created.',
                    ]);
                }

                /** @var Appointment $appointment */
                $appointment = $created['appointment'];
                $appointment->forceFill([
                    'booking_group_id' => $group->id,
                ])->save();
                $appointment->refresh();

                $appointments[] = $appointment;
                $packages[] = $created['package'];
                $totalDuration += max(1, (int) $appointment->duration_minutes);

                foreach ((array) ($created['warnings'] ?? []) as $warning) {
                    $warnings[] = sprintf('Treatment %d: %s', $index + 1, $warning);
                }

                // Each appointment keeps its own record, but the selected staff
                // member is reserved continuously from the first treatment start
                // through the end of the final treatment.
                if ($currentStart) {
                    $currentStart = $currentStart->copy()->addMinutes(
                        max(1, (int) $appointment->duration_minutes),
                    );
                }
            }

            $group->load([
                'client:id,name,email,phone',
                'createdBy:id,name,email',
                'appointments.service.category',
                'appointments.staff',
                'appointments.package.service',
            ]);

            return [
                'group' => $group,
                'appointments' => $appointments,
                'packages' => $packages,
                'warnings' => array_values(array_unique($warnings)),
                'total_duration_minutes' => $totalDuration,
            ];
        });
    }
}
