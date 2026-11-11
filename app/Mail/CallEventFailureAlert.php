<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CallEventFailureAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** @param array<string, string|int> $details */
    public function __construct(public array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action required: WhatsApp call link failed for '.$this->details['booking_reference']);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.admin-call-event-failure');
    }
}
