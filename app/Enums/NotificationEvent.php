<?php

namespace App\Enums;

enum NotificationEvent: string
{
    case BundlePurchaseConfirmed = 'bundle_purchase_confirmed';
    case IntroBookingConfirmed = 'intro_booking_confirmed';
    case AppointmentConfirmed = 'appointment_confirmed';
    case AppointmentReminder24Hours = 'appointment_reminder_24h';
    case AppointmentReminder1Hour = 'appointment_reminder_1h';
    case BookingCancelled = 'booking_cancelled';
    case BookingRescheduled = 'booking_rescheduled';
    case TeacherCancellation = 'teacher_cancellation';
    case BookingNoShow = 'booking_no_show';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';
    case BundleExpiry14Days = 'bundle_expiry_14d';
    case BundleExpiry3Days = 'bundle_expiry_3d';
}
