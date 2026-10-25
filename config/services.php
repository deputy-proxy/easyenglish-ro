<?php

return [
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'checkout_enabled' => (bool) env('STRIPE_CHECKOUT_ENABLED', false),
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
        'templates' => [
            'purchase_confirmation' => env('WHATSAPP_TEMPLATE_PURCHASE_CONFIRMATION', 'easyenglish_purchase_confirmation'),
            'intro_booking_confirmation' => env('WHATSAPP_TEMPLATE_INTRO_BOOKING_CONFIRMATION', 'easyenglish_intro_booking_confirmation'),
            'appointment_confirmation' => env('WHATSAPP_TEMPLATE_APPOINTMENT_CONFIRMATION', 'easyenglish_appointment_confirmation'),
            'appointment_reminder_24h' => env('WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_24H', 'easyenglish_appointment_reminder_24h'),
            'appointment_reminder_1h' => env('WHATSAPP_TEMPLATE_APPOINTMENT_REMINDER_1H', 'easyenglish_appointment_reminder_1h'),
            'booking_cancelled' => env('WHATSAPP_TEMPLATE_BOOKING_CANCELLED', 'easyenglish_booking_cancelled'),
            'booking_rescheduled' => env('WHATSAPP_TEMPLATE_BOOKING_RESCHEDULED', 'easyenglish_booking_rescheduled'),
            'teacher_cancellation' => env('WHATSAPP_TEMPLATE_TEACHER_CANCELLATION', 'easyenglish_teacher_cancellation'),
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
