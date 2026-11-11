<?php

return [
    'policy_version' => '2026-11-11',
    'contact_email' => (env('EASYENGLISH_CONTACT_EMAIL') ?: 'office@edventure.ro'),
    'company' => [
        'name' => 'ROAD CENTRE SRL',
        'cui' => '17808982',
        'trade_register' => 'J2005012930400',
        'address' => 'Bd. Uverturii 10, Bl. B1, Sc. 1, Et. 4, Ap. 50, Sector 6, București, 060942, România',
    ],
    'contact_whatsapp' => env('EASYENGLISH_CONTACT_WHATSAPP'),
    'retention_enabled' => env('DATA_RETENTION_ENABLED', false),
    'teacher_availability_enabled' => env('TEACHER_AVAILABILITY_ENABLED', false),
];
