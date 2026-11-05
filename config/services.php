<?php

$openWaRetryDelays = array_values(array_filter(
    array_map('intval', explode(',', (string) env('OPENWA_RETRY_DELAYS', '60,300,900,3600,14400'))),
    static fn (int $delay): bool => $delay > 0,
)) ?: [60, 300, 900, 3600, 14400];

return [
    'openwa' => [
        'enabled' => (bool) env('OPENWA_ENABLED', false),
        'apply_to_paid_sessions' => (bool) env('OPENWA_APPLY_TO_PAID_SESSIONS', false),
        'base_url' => env('OPENWA_BASE_URL'),
        'service_token' => env('OPENWA_SERVICE_TOKEN'),
        'connect_timeout' => (int) env('OPENWA_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('OPENWA_TIMEOUT', 30),
        'max_attempts' => (int) env('OPENWA_MAX_ATTEMPTS', 5),
        'retry_delays_seconds' => $openWaRetryDelays,
    ],

    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'api_base' => 'https://www.googleapis.com/calendar/v3',
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'checkout_enabled' => (bool) env('STRIPE_CHECKOUT_ENABLED', false),
    ],

    'contact_verification' => [
        'mode' => env('CONTACT_VERIFICATION_MODE', 'email_and_whatsapp'),
    ],

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'api_version' => env('WHATSAPP_API_VERSION', 'v26.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'template_languages' => ['ro' => env('WHATSAPP_TEMPLATE_LANGUAGE_RO', 'ro'), 'en' => env('WHATSAPP_TEMPLATE_LANGUAGE_EN', 'en_US')],
        'contact_verification_template_languages' => [
            'ro' => env('WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_RO', env('WHATSAPP_TEMPLATE_LANGUAGE_RO', 'ro')),
            'en' => env('WHATSAPP_TEMPLATE_CONTACT_VERIFICATION_LANGUAGE_EN', 'en'),
        ],
        'templates' => [
            'contact_verification' => env('WHATSAPP_TEMPLATE_CONTACT_VERIFICATION', 'easyenglish_activation'),
            'purchase_confirmation' => env('WHATSAPP_TEMPLATE_PURCHASE_CONFIRMATION', 'easyenglish_purchase_confirmation'),
            'intro_booking_confirmation' => env('WHATSAPP_TEMPLATE_INTRO_BOOKING_CONFIRMATION', 'easyenglish_intro_booking_confirmation'),
            'appointment_confirmation' => env('WHATSAPP_TEMPLATE_APPOINTMENT_CONFIRMATION', 'easyenglish_appointment_confirmation'),
            'appointment_reminder_24h' => env('WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_24H', 'easyenglish_appointment_reminder_24h'),
            'appointment_reminder_1h' => env('WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_1H', 'easyenglish_appointment_reminder_1h'),
            'booking_cancelled' => env('WHATSAPP_TEMPLATE_BOOKING_CANCELLED', 'easyenglish_booking_cancelled'),
            'booking_rescheduled' => env('WHATSAPP_TEMPLATE_BOOKING_RESCHEDULED', 'easyenglish_booking_rescheduled'),
            'teacher_cancellation' => env('WHATSAPP_TEMPLATE_TEACHER_CANCELLATION', 'easyenglish_teacher_cancellation'),
            'teacher_booking_confirmed' => env('WHATSAPP_TEMPLATE_TEACHER_BOOKING_CONFIRMED', 'easyenglish_teacher_booking_confirmation'),
            'teacher_booking_rescheduled' => env('WHATSAPP_TEMPLATE_TEACHER_BOOKING_RESCHEDULED', 'easyenglish_teacher_booking_rescheduled'),
            'customer_call_link_ready' => env('WHATSAPP_TEMPLATE_CUSTOMER_CALL_LINK_READY', 'easyenglish_call_link_ready'),
            'teacher_call_link_ready' => env('WHATSAPP_TEMPLATE_TEACHER_CALL_LINK_READY', 'easyenglish_teacher_call_link_ready'),
            'teacher_booking_cancelled' => env('WHATSAPP_TEMPLATE_TEACHER_BOOKING_CANCELLED', 'easyenglish_teacher_booking_cancelled'),
            'booking_no_show' => env('WHATSAPP_TEMPLATE_BOOKING_NO_SHOW', 'easyenglish_booking_no_show'),
            'refund_succeeded' => env('WHATSAPP_TEMPLATE_REFUND_SUCCEEDED', 'easyenglish_refund_succeeded'),
            'refund_failed' => env('WHATSAPP_TEMPLATE_REFUND_FAILED', 'easyenglish_refund_failed'),
            'bundle_expiry_14d' => env('WHATSAPP_TEMPLATE_BUNDLE_EXPIRY_14D', 'easyenglish_bundle_expiry_14d'),
            'bundle_expiry_3d' => env('WHATSAPP_TEMPLATE_BUNDLE_EXPIRY_3D', 'easyenglish_bundle_expiry_3d'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
