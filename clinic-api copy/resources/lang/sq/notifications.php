<?php

return [
    'booked' => [
        'title' => 'Termini u rezervua',
        'body'  => 'Termini juaj për :service më :date në ora :time është rezervuar.',
        'mail_subject' => '✔ Termini u rezervua',
    ],

    'status' => [
        'title' => 'Statusi i terminit u përditësua',
        'body'  => 'Termini juaj për :service më :date në ora :time tani është :status.',
        'mail_subject' => 'ℹ Përditësim i statusit të terminit',
    ],

    'reminder' => [
        'title' => 'Përkujtues për termin',
        'body'  => 'Përkujtues për :service më :date në ora :time.',
        'mail_subject' => '⏰ Përkujtues për termin',
    ],
];
