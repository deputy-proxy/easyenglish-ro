<?php

namespace App\Console\Commands;

use App\Enums\NotificationEvent;
use App\Mail\ContactVerificationEmail;
use App\Mail\OpenWaSessionDisconnected;
use App\Mail\TransactionalNotificationEmail;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

class PreviewEmails extends Command
{
    protected $signature = 'emails:preview
        {--event=all : Event key, verification, openwa_disconnected, or all}
        {--locale=both : ro, en, or both}
        {--to= : Recipient address for an explicitly requested test send}
        {--send : Send the selected preview email instead of only rendering it}
        {--force : Allow sending a preview email when APP_ENV is production}';

    protected $description = 'Render all EasyEnglish email previews or send one explicitly selected test email';

    public function handle(): int
    {
        $event = (string) $this->option('event');
        $localeOption = (string) $this->option('locale');
        $recipient = $this->option('to');
        $send = (bool) $this->option('send');

        $eventKeys = [
            ...array_map(static fn (NotificationEvent $event): string => $event->value, NotificationEvent::cases()),
            'verification',
            'openwa_disconnected',
        ];

        if ($event !== 'all' && ! in_array($event, $eventKeys, true)) {
            $this->components->error("Unknown email event [{$event}].");

            return self::INVALID;
        }

        if (! in_array($localeOption, ['ro', 'en', 'both'], true)) {
            $this->components->error('The locale must be ro, en, or both.');

            return self::INVALID;
        }

        if ($send && ($event === 'all' || $localeOption === 'both')) {
            $this->components->error('For safety, sending requires one specific --event and one specific --locale.');
            $this->line('Example: php artisan emails:preview --event=appointment_confirmed --locale=ro --send --to=you@example.com');

            return self::INVALID;
        }

        if ($send && (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false)) {
            $this->components->error('A valid --to email address is required when --send is used.');

            return self::INVALID;
        }

        if (! $send && $recipient !== null) {
            $this->components->error('The --to option is only used together with --send.');

            return self::INVALID;
        }

        if ($send && app()->environment('production') && ! $this->option('force')) {
            $this->components->error('Sending from production requires the additional --force option.');

            return self::FAILURE;
        }

        $events = $event === 'all' ? $eventKeys : [$event];
        $locales = $localeOption === 'both' ? ['ro', 'en'] : [$localeOption];
        $rendered = 0;

        foreach ($events as $eventKey) {
            foreach ($locales as $locale) {
                $mailable = $this->makeMailable($eventKey, $locale);
                $baseName = "{$eventKey}-{$locale}";

                Storage::disk('local')->put("email-previews/{$baseName}.html", $mailable->render());
                Storage::disk('local')->put("email-previews/{$baseName}.txt", $this->renderPlainText($eventKey, $locale, $mailable));
                $rendered++;

                if ($send) {
                    $mailable->subject('[PREVIEW] '.$this->mailableSubject($mailable));
                    Mail::to((string) $recipient)->send($mailable);
                }

                $this->line(sprintf('  %s (%s)', $eventKey, strtoupper($locale)));
            }
        }

        $outputPath = Storage::disk('local')->path('email-previews');

        $this->newLine();
        $this->components->info("Rendered {$rendered} email variant(s) to {$outputPath}.");
        $this->line('Each variant includes an .html file and a .txt file.');

        if ($send) {
            $this->components->info("Sent one preview email to {$recipient}. The subject is prefixed with [PREVIEW].");
        } else {
            $this->line('No email was sent. Use --send with one --event, one --locale, and --to to send a test.');
        }

        return self::SUCCESS;
    }

    private function makeMailable(string $event, string $locale): Mailable
    {
        if ($event === 'verification') {
            return new ContactVerificationEmail(
                $locale,
                '482951',
                'https://preview.invalid/email-verification?token=preview-only',
            );
        }

        if ($event === 'openwa_disconnected') {
            return new OpenWaSessionDisconnected(
                'easyenglish-openwa-preview',
                'connection_lost',
                CarbonImmutable::now('UTC'),
                $locale,
            );
        }

        $copy = Lang::get("email.events.{$event}", [], $locale);
        if (! is_array($copy) || ! isset($copy['subject'], $copy['heading'], $copy['intro'])) {
            throw ValidationException::withMessages([
                'event' => "The {$event} email is missing its {$locale} translation.",
            ]);
        }

        $teacherEvent = in_array($event, [
            NotificationEvent::TeacherBookingConfirmed->value,
            NotificationEvent::TeacherBookingRescheduled->value,
            NotificationEvent::TeacherCallLinkReady->value,
            NotificationEvent::TeacherBookingCancelled->value,
        ], true);
        $name = $teacherEvent ? 'Maria' : 'Alex';

        return new TransactionalNotificationEmail([
            'locale' => $locale,
            'subject' => $copy['subject'],
            'heading' => $copy['heading'],
            'greeting' => Lang::get('email.greeting', ['name' => $name], $locale),
            'intro' => $copy['intro'],
            'details' => $this->sampleDetails($event, $locale),
            'ctaUrl' => $this->sampleActionUrl($event),
            'ctaLabel' => $this->sampleActionLabel($event, $locale),
            'footer' => Lang::get('email.footer', [], $locale),
            'success' => in_array($event, [
                NotificationEvent::IntroBookingConfirmed->value,
                NotificationEvent::AppointmentConfirmed->value,
                NotificationEvent::BundlePurchaseConfirmed->value,
                NotificationEvent::RefundSucceeded->value,
                NotificationEvent::CustomerCallLinkReady->value,
            ], true),
        ]);
    }

    /**
     * @return list<array{label:string,value:string}>
     */
    private function sampleDetails(string $event, string $locale): array
    {
        $label = static fn (string $key): string => (string) Lang::get("email.labels.{$key}", [], $locale);
        $detail = static fn (string $key, string $value): array => ['label' => $label($key), 'value' => $value];
        $date = CarbonImmutable::now()->settings(['locale' => $locale])->addDays(2)->translatedFormat('j F Y, H:i');

        if ($event === NotificationEvent::BundlePurchaseConfirmed->value) {
            return [
                $detail('bundle', $locale === 'ro' ? 'Pachet 5 sesiuni' : '5-session bundle'),
                $detail('amount_paid', '345 RON'),
                $detail('paid_sessions', '5'),
                $detail('free_sessions_available', '1'),
                $detail('bundle_status', $locale === 'ro' ? 'Activ' : 'Active'),
                $detail('bundle_expires', CarbonImmutable::now()->settings(['locale' => $locale])->addMonths(3)->translatedFormat('j F Y')),
            ];
        }

        if (in_array($event, [NotificationEvent::RefundSucceeded->value, NotificationEvent::RefundFailed->value], true)) {
            return [
                $detail('refund_amount', '345 RON'),
                $detail('refund_status', $locale === 'ro' ? 'Confirmată' : 'Confirmed'),
                $detail('refund_reason', $locale === 'ro' ? 'Exemplu pentru previzualizare' : 'Preview example'),
            ];
        }

        if (in_array($event, [NotificationEvent::BundleExpiry14Days->value, NotificationEvent::BundleExpiry3Days->value], true)) {
            return [
                $detail('bundle', $locale === 'ro' ? 'Pachet 5 sesiuni' : '5-session bundle'),
                $detail('credits_remaining', '2'),
                $detail('bundle_expires', CarbonImmutable::now()->settings(['locale' => $locale])->addDays($event === NotificationEvent::BundleExpiry14Days->value ? 14 : 3)->translatedFormat('j F Y')),
            ];
        }

        if (in_array($event, [
            NotificationEvent::TeacherBookingConfirmed->value,
            NotificationEvent::TeacherBookingRescheduled->value,
            NotificationEvent::TeacherCallLinkReady->value,
            NotificationEvent::TeacherBookingCancelled->value,
        ], true)) {
            $details = [
                $detail('customer_name', 'Alex Popescu'),
                $detail('customer_email', 'alex@example.com'),
                $detail('customer_phone', '+40 722 123 456'),
                $detail('english_level', 'B1'),
                $detail('session_goal', $locale === 'ro' ? 'Conversații profesionale' : 'Work conversations'),
                $detail('session_notes', $locale === 'ro' ? 'Exemplu de notițe pentru profesor.' : 'Sample preparation notes for the teacher.'),
                $detail('appointment_date', $date),
                $detail('duration', '30 '.$label('minutes')),
            ];

            if ($event === NotificationEvent::TeacherCallLinkReady->value) {
                $details[] = $detail('call_link', 'https://preview.invalid/call');
                $details[] = $detail('booking_feedback_link', 'https://preview.invalid/teacher/bookings/preview');
            }

            return $details;
        }

        $details = [
            $detail('appointment_date', $date),
            $detail('duration', '15 '.$label('minutes')),
            $detail('timezone', 'Europe/Bucharest'),
        ];

        if ($event === NotificationEvent::CustomerCallLinkReady->value) {
            $details[] = $detail('join_instructions', (string) Lang::get('email.values.join_whatsapp_instructions', [], $locale));
        } elseif (in_array($event, [NotificationEvent::BookingCancelled->value, NotificationEvent::TeacherCancellation->value, NotificationEvent::BookingNoShow->value], true)) {
            $details[] = $detail('credit_outcome', $locale === 'ro' ? 'Creditul sesiunii a fost restituit.' : 'The session credit was restored.');
        }

        return $details;
    }

    private function sampleActionUrl(string $event): string
    {
        if ($event === NotificationEvent::CustomerCallLinkReady->value) {
            return 'https://preview.invalid/call';
        }

        if (in_array($event, [
            NotificationEvent::TeacherBookingConfirmed->value,
            NotificationEvent::TeacherBookingRescheduled->value,
            NotificationEvent::TeacherCallLinkReady->value,
            NotificationEvent::TeacherBookingCancelled->value,
        ], true)) {
            return 'https://preview.invalid/teacher/bookings/preview';
        }

        if (in_array($event, [
            NotificationEvent::BundlePurchaseConfirmed->value,
            NotificationEvent::BundleExpiry14Days->value,
            NotificationEvent::BundleExpiry3Days->value,
        ], true)) {
            return 'https://preview.invalid/dashboard/bundles';
        }

        return 'https://preview.invalid/dashboard';
    }

    private function sampleActionLabel(string $event, string $locale): string
    {
        $key = match ($event) {
            NotificationEvent::CustomerCallLinkReady->value => 'join_whatsapp_call',
            NotificationEvent::TeacherBookingConfirmed->value,
            NotificationEvent::TeacherBookingRescheduled->value,
            NotificationEvent::TeacherCallLinkReady->value,
            NotificationEvent::TeacherBookingCancelled->value => 'open_booking_feedback',
            NotificationEvent::BundlePurchaseConfirmed->value,
            NotificationEvent::BundleExpiry14Days->value,
            NotificationEvent::BundleExpiry3Days->value => 'schedule_sessions',
            default => 'manage_appointment',
        };

        return (string) Lang::get("email.actions.{$key}", [], $locale);
    }

    private function mailableSubject(Mailable $mailable): string
    {
        return match (true) {
            $mailable instanceof TransactionalNotificationEmail => (string) $mailable->envelope()->subject,
            $mailable instanceof ContactVerificationEmail => (string) $mailable->envelope()->subject,
            $mailable instanceof OpenWaSessionDisconnected => (string) $mailable->envelope()->subject,
            default => 'EasyEnglish email preview',
        };
    }

    private function renderPlainText(string $event, string $locale, Mailable $mailable): string
    {
        if ($mailable instanceof TransactionalNotificationEmail) {
            return View::make('emails.transactional-text', ['messageData' => $mailable->messageData])->render();
        }

        if ($mailable instanceof ContactVerificationEmail) {
            return View::make('emails.verification-text', [
                'locale' => $locale,
                'code' => $mailable->code,
                'backupUrl' => $mailable->backupUrl,
            ])->render();
        }

        if ($mailable instanceof OpenWaSessionDisconnected) {
            return View::make('emails.openwa-disconnected-text', [
                'locale' => $locale,
                'sessionId' => $mailable->sessionId,
                'reason' => $mailable->reason,
                'disconnectedAt' => $mailable->disconnectedAt,
            ])->render();
        }

        throw new \LogicException("Unsupported preview mailable for {$event}.");
    }
}
