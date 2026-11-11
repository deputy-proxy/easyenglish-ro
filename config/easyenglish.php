<?php

return [
    'policy_version' => '2026-11-11',
    'contact_email' => (env('EASYENGLISH_CONTACT_EMAIL') ?: 'office@edventure.ro'),
    'company' => [
        'name' => 'ROAD CENTRE SRL',
        'cui' => '17808982',
        'trade_register' => 'J2005012930400',
        'address' => 'Strada Dr. Nicolae D. Staicovici nr. 4, Sector 5, București, România',
    ],
    'contact_whatsapp' => env('EASYENGLISH_CONTACT_WHATSAPP'),
    'retention_enabled' => env('DATA_RETENTION_ENABLED', false),
    'teacher_availability_enabled' => env('TEACHER_AVAILABILITY_ENABLED', false),
];
