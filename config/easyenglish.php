<?php

return [
    'policy_version' => env('EASYENGLISH_POLICY_VERSION', '2026-10-25'),
    'contact_email' => env('EASYENGLISH_CONTACT_EMAIL'),
    'retention_enabled' => env('DATA_RETENTION_ENABLED', false),
    'teacher_availability_enabled' => env('TEACHER_AVAILABILITY_ENABLED', false),
];
