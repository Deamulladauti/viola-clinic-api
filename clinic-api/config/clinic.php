<?php

return [
    // Local timezone used by Carbon::now()
    'timezone' => env('CLINIC_TZ', config('app.timezone', 'UTC')),

    // Opening/closing hours for availability & booking checks
    'workday' => [
        'start' => env('CLINIC_WORKDAY_START', '10:00:00'),
        'end'   => env('CLINIC_WORKDAY_END',   '19:00:00'),
    ],

    // Slot step (granularity) for availability listing
    'slot_step_minutes' => (int) env('CLINIC_SLOT_STEP', 15),

    // Guests must book at least N minutes from "now"
    'min_notice_minutes' => (int) env('CLINIC_MIN_NOTICE', 30),

    // Optional: disallow offering availability for past dates
    'allow_past_dates_in_availability' => (bool) env('CLINIC_ALLOW_PAST_DATES', false),
];
