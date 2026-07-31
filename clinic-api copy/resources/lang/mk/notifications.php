<?php

return [
    'booked' => [
        'title' => 'Терминот е резервиран',
        'body'  => 'Вашиот термин за :service на :date во :time е резервиран.',
        'mail_subject' => '✔ Терминот е резервиран',
    ],

    'status' => [
        'title' => 'Статусот на терминот е ажуриран',
        'body'  => 'Вашиот термин за :service на :date во :time сега е :status.',
        'mail_subject' => 'ℹ Ажурирање на статусот на терминот',
    ],

    'reminder' => [
        'title' => 'Потсетник за термин',
        'body'  => 'Потсетник за :service на :date во :time.',
        'mail_subject' => '⏰ Потсетник за термин',
    ],
];
