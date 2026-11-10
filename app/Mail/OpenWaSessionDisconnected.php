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
        public string $messageLocale = 'en',
    ) {
        $this->messageLocale = in_array($messageLocale, ['ro', 'en'], true) ? $messageLocale : 'en';
        $this->locale($this->messageLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageLocale === 'ro'
            ? 'Acțiune necesară: sesiunea WhatsApp EasyEnglish este deconectată'
            : 'Action required: EasyEnglish WhatsApp disconnected');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.openwa-disconnected',
            text: 'emails.openwa-disconnected-text',
            with: ['locale' => $this->messageLocale],
        );
    }
}
