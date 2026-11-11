<?php

namespace App\Services\Calendar;

use App\Models\Booking;

class CalendarInvitationFactory
{
    public function make(Booking $booking, string $attendeeEmail, string $attendeeName, ?string $callUrl = null): string
    {
        $teacher = $booking->teacher;
        $organizerEmail = (string) config('mail.from.address');
        $organizerName = (string) config('mail.from.name', 'EasyEnglish');
        $description = [
            'EasyEnglish session',
            'Customer: '.$booking->customer_name,
            'Teacher: '.($booking->teacher_id !== null ? $teacher->name : 'To be confirmed'),
            'Duration: '.$booking->starts_at->diffInMinutes($booking->ends_at).' minutes',
            $callUrl !== null ? 'Join via WhatsApp: '.$callUrl : 'Join via the WhatsApp call link sent separately before the session.',
        ];

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//EasyEnglish//Session Booking//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:booking-'.$booking->public_reference.'@easyenglish.ro',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$booking->starts_at->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$booking->ends_at->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape('EasyEnglish Session'),
            'LOCATION:'.$this->escape($callUrl ?: 'WhatsApp call'),
            'DESCRIPTION:'.$this->escape(implode("\n", $description)),
            'STATUS:CONFIRMED',
            'SEQUENCE:'.(int) $booking->reschedule_count,
            'TRANSP:OPAQUE',
            'ORGANIZER;CN='.$this->parameter($organizerName).':mailto:'.$organizerEmail,
            'ATTENDEE;CN='.$this->parameter($attendeeName).';ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:'.$attendeeEmail,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    private function parameter(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value).'"';
    }

    private function fold(string $line): string
    {
        $folded = '';
        $length = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $characterLength = strlen($character);
            if ($length + $characterLength > 73) {
                $folded .= "\r\n ";
                $length = 1;
            }
            $folded .= $character;
            $length += $characterLength;
        }

        return $folded;
    }
}
