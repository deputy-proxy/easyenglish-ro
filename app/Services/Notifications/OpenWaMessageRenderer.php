<?php

namespace App\Services\Notifications;

use App\Data\Notifications\WhatsAppTemplateMessage;
use RuntimeException;

class OpenWaMessageRenderer
{
    public function render(WhatsAppTemplateMessage $message): string
    {
        $parameters = $message->bodyParameters;
        $key = $message->template;
        $english = str_starts_with(strtolower($message->language), 'en');
        $templates = [
            'contact_verification' => ['en' => 'Your Easy English verification code is %s. It expires in 30 minutes. Do not share this code with anyone.', 'ro' => 'Codul tău de verificare Easy English este %s. Expiră în 30 de minute. Nu comunica acest cod nimănui.'],
            'purchase_confirmation' => ['en' => 'Hi %s, your %s bundle payment of %s is confirmed. It expires on %s. Status: %s.', 'ro' => 'Bună, %s! Pachetul %s a fost achitat cu suma de %s. Expiră la %s. Stare: %s.'],
            'intro_booking_confirmation' => ['en' => 'Hi %s, your free 15-minute introductory session is confirmed for %s (%s). Teacher: %s. Keep WhatsApp available for the call.', 'ro' => 'Bună, %s! Sesiunea introductivă gratuită de 15 minute este confirmată pentru %s (%s). Profesor: %s. Păstrează WhatsApp disponibil pentru apel.'],
            'appointment_confirmation' => ['en' => 'Hi %s, your Easy English session is confirmed for %s (%s). Teacher: %s. Keep WhatsApp available for the call.', 'ro' => 'Bună, %s! Sesiunea Easy English este confirmată pentru %s (%s). Profesor: %s. Păstrează WhatsApp disponibil pentru apel.'],
            'appointment_reminder_24h' => ['en' => 'Hi %s, reminder: your Easy English session is scheduled for %s (%s), about 24 hours from now. Teacher: %s. Keep WhatsApp available for the call.', 'ro' => 'Bună, %s! Memento: sesiunea Easy English este programată pentru %s (%s), peste aproximativ 24 de ore. Profesor: %s. Păstrează WhatsApp disponibil pentru apel.'],
            'appointment_reminder_1h' => ['en' => 'Hi %s, your Easy English session starts in about one hour, at %s (%s). Teacher: %s. Keep WhatsApp available for the call.', 'ro' => 'Bună, %s! Sesiunea Easy English începe peste aproximativ o oră, la %s (%s). Profesor: %s. Păstrează WhatsApp disponibil pentru apel.'],
            'booking_cancelled' => ['en' => 'Hi %s, your Easy English session on %s has been cancelled. Credit outcome: %s.', 'ro' => 'Bună, %s! Sesiunea Easy English din %s a fost anulată. Situația creditului: %s.'],
            'booking_rescheduled' => ['en' => 'Hi %s, your Easy English session has changed from %s to %s. Teacher: %s.', 'ro' => 'Bună, %s! Sesiunea Easy English a fost mutată de la %s la %s. Profesor: %s.'],
            'teacher_cancellation' => ['en' => 'Hi %s, your teacher had to cancel the session on %s. Updated bundle expiry: %s.', 'ro' => 'Bună, %s! Profesorul a trebuit să anuleze sesiunea din %s. Noua dată de expirare a pachetului: %s.'],
            'booking_no_show' => ['en' => 'Hi %s, the Easy English session on %s was recorded as missed. Outcome: %s.', 'ro' => 'Bună, %s! Absența de la sesiunea din %s a fost înregistrată. Situație: %s.'],
            'refund_succeeded' => ['en' => 'Hi %s, your Easy English refund of %s was confirmed. Reason: %s.', 'ro' => 'Bună, %s! Rambursarea Easy English de %s a fost confirmată. Motiv: %s.'],
            'refund_failed' => ['en' => 'Hi %s, your Easy English refund of %s was not completed. Reason: %s.', 'ro' => 'Bună, %s! Rambursarea Easy English de %s nu a fost finalizată. Motiv: %s.'],
            'bundle_expiry_14d' => ['en' => 'Hi %s, your %s bundle has %s sessions remaining and expires on %s.', 'ro' => 'Bună, %s! Pachetul %s mai are %s sesiuni și expiră la %s.'],
            'bundle_expiry_3d' => ['en' => 'Hi %s, your %s bundle has %s sessions remaining and expires on %s. It expires in about 3 days.', 'ro' => 'Bună, %s! Pachetul %s mai are %s sesiuni și expiră la %s. Expiră în aproximativ 3 zile.'],
            'teacher_booking_confirmed' => ['en' => 'Hi %s, a session is assigned to you. Learner: %s (%s, %s). Level: %s. Goal: %s. Notes: %s. Appointment: %s at %s (%s). Duration: %s minutes. Details: %s.'],
            'teacher_booking_rescheduled' => ['en' => 'Hi %s, %s’s session has moved from %s at %s to %s at %s. Duration: %s minutes. Learner contact: %s, %s. Timezone: %s. Details: %s.'],
            'customer_call_link_ready' => ['en' => 'Hi %s, your Easy English session is scheduled for %s at %s (%s). Duration: %s minutes. Join your WhatsApp call here: %s. %s', 'ro' => 'Bună, %s! Sesiunea Easy English este programată pentru %s la %s (%s). Durata: %s minute. Intră în apelul WhatsApp aici: %s. %s'],
            'teacher_call_link_ready' => ['en' => 'Hi %s, your session with %s is scheduled for %s at %s (%s). Duration: %s minutes. Learner contact: %s, %s. Level: %s. Goal: %s. Notes: %s. Call link: %s. Booking: %s.'],
            'teacher_booking_cancelled' => ['en' => 'Hi %s, %s’s appointment on %s has been cancelled or reassigned. Update: %s.'],
        ];
        $format = $templates[$key][$english ? 'en' : 'ro'] ?? $templates[$key]['en'] ?? null;
        if (! is_string($format)) {
            throw new RuntimeException('OpenWA has no text rendering for the configured message template.');
        }
        $expected = substr_count($format, '%s');
        if ($expected !== count($parameters)) {
            throw new RuntimeException('OpenWA message parameters do not match template '.$key.' (expected '.$expected.', received '.count($parameters).').');
        }

        $optOut = $english ? 'Reply STOP to opt out of Easy English service messages.' : 'Răspunde STOP pentru a opri mesajele de serviciu Easy English.';

        return vsprintf($format, $parameters)."\n\n".$optOut;
    }
}
