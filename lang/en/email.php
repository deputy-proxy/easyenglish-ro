<?php

return [
    'greeting' => 'Hello :name,',
    'footer' => 'This is a service email from Easy English about your booking or bundle. It is not a marketing message. If you did not expect it, contact the Easy English team.',
    'labels' => [
        'bundle' => 'Bundle',
        'amount_paid' => 'Amount paid',
        'paid_sessions' => 'Paid sessions included',
        'free_sessions_available' => 'Included free sessions currently available',
        'bundle_status' => 'Current bundle status',
        'bundle_expires' => 'Bundle expires',
        'appointment_date' => 'Appointment time (Bucharest)',
        'previous_appointment_date' => 'Previous appointment time',
        'duration' => 'Duration',
        'timezone' => 'Time zone',
        'credit_outcome' => 'Credit outcome',
        'refund_amount' => 'Refund amount',
        'refund_status' => 'Refund status',
        'refund_reason' => 'Reason',
        'credits_remaining' => 'Sessions remaining',
        'minutes' => 'minutes',
    ],
    'values' => [
        'credit_restored' => 'The session credit was restored to your bundle.',
        'credit_refunded' => 'The session credit was included in the confirmed bundle refund.'
        'credit_forfeited' => 'The session credit was forfeited under the cancellation policy.',
        'free_offer_used' => 'The one-time free introductory offer has been used.',
        'succeeded' => 'Confirmed by Stripe',
        'failed' => 'Not completed by Stripe; reserved credits were restored where applicable.',
        'not_available' => 'Not available',
        'active' => 'Active',
        'refunded' => 'Refunded',
        'expired' => 'Expired',
        'pending_payment' => 'Awaiting payment',
        'voided' => 'Voided',
        'cancelled' => 'Cancelled',
    ],
    'actions' => [
        'schedule_sessions' => 'Schedule your sessions',
        'manage_sessions' => 'Manage your sessions',
        'manage_appointment' => 'Manage appointment',
    ],
    'events' => [
        'bundle_purchase_confirmed' => [
            'subject' => 'Your Easy English bundle purchase is confirmed',
            'heading' => 'Your bundle purchase is confirmed',
            'intro' => 'Your payment was verified and the bundle was activated at purchase time. Its current status is shown below. Individual appointments are not scheduled automatically, so choose the times that work for you if credits remain.',
        ],
        'intro_booking_confirmed' => [
            'subject' => 'Your free introductory session is confirmed',
            'heading' => 'Your introductory session is booked',
            'intro' => 'Your 15-minute introductory conversation is confirmed. Keep WhatsApp available at the scheduled time so your teacher can contact you for the call.',
        ],
        'appointment_confirmed' => [
            'subject' => 'Your Easy English appointment is confirmed',
            'heading' => 'Your appointment is confirmed',
            'intro' => 'Your 15-minute conversation is booked. Keep WhatsApp installed and available at the scheduled time so your teacher can contact you for the call.',
        ],
        'appointment_reminder_1h' => [
            'subject' => 'Reminder: your Easy English session starts in about an hour',
            'heading' => 'Your session starts soon',
            'intro' => 'Your 15-minute conversation is scheduled to start in about one hour. Please keep WhatsApp available for the call.',
        ],
        'appointment_reminder_24h' => [
            'subject' => 'Reminder: your Easy English session is tomorrow',
            'heading' => 'Your session is coming up',
            'intro' => 'This is a reminder that your 15-minute conversation is scheduled for about 24 hours from now. Please keep WhatsApp available for the call.',
        ],
        'booking_cancelled' => [
            'subject' => 'Your Easy English appointment was cancelled',
            'heading' => 'Appointment cancelled',
            'intro' => 'Your appointment has been cancelled. The details below reflect the appointment that was cancelled and the applicable credit outcome.',
        ],
        'booking_rescheduled' => [
            'subject' => 'Your Easy English appointment was rescheduled',
            'heading' => 'Your appointment time has changed',
            'intro' => 'Your appointment has been moved to a new time. Please use the updated time below and keep WhatsApp available for the call.',
        ],
        'booking_no_show' => [
            'subject' => 'An update about your Easy English session',
            'heading' => 'The session was recorded as a no-show',
            'intro' => 'The Easy English team recorded that the session was missed. The applicable credit or one-time free offer outcome is shown below. Contact the team if you believe this was recorded incorrectly.',
        ],
        'teacher_cancellation' => [
            'subject' => 'An update to your Easy English appointment',
            'heading' => 'Your teacher had to cancel this session',
            'intro' => 'We are sorry for the change. Your session credit is restored. If your bundle validity needed an extension, the updated expiry is shown below.',
        ],
        'refund_succeeded' => [
            'subject' => 'Your Easy English refund is confirmed',
            'heading' => 'Your refund was confirmed',
            'intro' => 'Stripe confirmed the refund shown below. Your bundle balance has been updated to match the confirmed refund.',
        ],
        'refund_failed' => [
            'subject' => 'An update about your Easy English refund',
            'heading' => 'Your refund was not completed',
            'intro' => 'Stripe did not complete the refund shown below. Any credits reserved for this attempt have been restored where applicable. Contact the Easy English team if you need help.',
        ],
        'bundle_expiry_14d' => [
            'subject' => 'Your Easy English bundle expires in about 14 days',
            'heading' => 'Your bundle is nearing expiry',
            'intro' => 'Your bundle expires in about 14 days. Schedule any remaining sessions before the expiry time shown below.',
        ],
        'bundle_expiry_3d' => [
            'subject' => 'Your Easy English bundle expires in about 3 days',
            'heading' => 'Your bundle expires soon',
            'intro' => 'Your bundle expires in about 3 days. Schedule any remaining sessions before the expiry time shown below.',
        ],
    ],
];
