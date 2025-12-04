<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Appointment;

class AppointmentPolicy
{
    // Staff can update only their own appointments
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->hasRole('staff')
        && $user->staff
        && (int) $appointment->staff_id === (int) $user->staff->id;
    }

    // app/Policies/AppointmentPolicy.php
    public function view(User $user, Appointment $appointment): bool
    {
        $staff = $user->staff;
        return $staff && (int)$appointment->staff_id === (int)$staff->id;
    }

}