<?php
return [
    'booked' => [
        'title' => 'Appointment booked',
        'body'  => 'Your appointment for :service on :date at :time is booked.',
        'mail_subject' => '✔ Appointment booked',
    ],
    'status' => [
        'title' => 'Appointment updated',
        'body'  => 'Your appointment for :service on :date at :time is now :status.',
        'mail_subject' => 'ℹ Appointment status updated',
    ],
    'reminder' => [
        'title' => 'Appointment reminder',
        'body'  => 'Reminder for :service on :date at :time.',
        'mail_subject' => '⏰ Appointment reminder',
    ],
];
