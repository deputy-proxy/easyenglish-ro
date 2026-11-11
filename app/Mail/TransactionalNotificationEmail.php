<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionalNotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array{locale:string,subject:string,heading:string,greeting:string,intro:string,details:list<array{label:string,value:string}>,ctaUrl:?string,ctaLabel:?string,footer:string,success?:bool,calendarInvitation?:string} $messageData */
    public function __construct(public array $messageData)
    {
        $this->locale($messageData['locale']);

        if (is_string($messageData['calendarInvitation'] ?? null)) {
            $this->attachData($messageData['calendarInvitation'], 'easyenglish-session.ics', [
                'mime' => 'text/calendar; charset=UTF-8; method=REQUEST',
            ]);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageData['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transactional',
            text: 'emails.transactional-text',
            with: ['messageData' => $this->messageData],
        );
    }
}
