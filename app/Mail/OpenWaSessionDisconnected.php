<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OpenWaSessionDisconnected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $reason,
        public CarbonImmutable $disconnectedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action required: EasyEnglish WhatsApp disconnected');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.openwa-disconnected');
    }
}
