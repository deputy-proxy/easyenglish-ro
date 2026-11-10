<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactVerificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $messageLocale;

    public function __construct(
        string $locale,
        public string $code,
        public string $backupUrl,
    ) {
        $this->messageLocale = $locale;
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageLocale === 'en'
            ? 'Verify your Easy English email'
            : 'Verifică adresa de e-mail Easy English');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verification', text: 'emails.verification-text', with: ['locale' => $this->messageLocale]);
    }
}
