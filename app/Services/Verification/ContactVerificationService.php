<?php

namespace App\Services\Verification;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Models\ContactVerificationChallenge;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ContactVerificationService
{
    public function __construct(private readonly WhatsAppGateway $whatsapp) {}

    public function start(string $email, string $phone, string $locale, string $returnTo = 'booking', ?int $returnBundleId = null): ContactVerificationChallenge
    {
        ContactVerificationChallenge::query()->where('expires_at', '<', now()->subDay())->delete();
        $email = mb_strtolower(trim($email));
        $phone = PhoneNumber::normalize($phone);
        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        if (! $emailOnly && (! (bool) config('services.whatsapp.enabled')
            || ! is_string(config('services.whatsapp.access_token')) || config('services.whatsapp.access_token') === ''
            || ! is_string(config('services.whatsapp.phone_number_id')) || config('services.whatsapp.phone_number_id') === '')) {
            throw new RuntimeException('WhatsApp verification delivery is not configured.');
        }
        if (! app()->environment('testing') && $this->usesNonDeliveringMailer((string) config('mail.default'))) {
            throw new RuntimeException('Email verification requires a real delivery transport; log/array transports can expose or discard verification secrets.');
        }
        $emailLimitKey = 'contact-verification:email:'.hash('sha256', $email);
        $phoneLimitKey = 'contact-verification:phone:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($emailLimitKey, 5) || RateLimiter::tooManyAttempts($phoneLimitKey, 5)) {
            throw ValidationException::withMessages(['verification' => __('public.verification_rate_limited')]);
        }
        RateLimiter::hit($emailLimitKey, 3600);
        RateLimiter::hit($phoneLimitKey, 3600);
        $existing = ContactVerificationChallenge::query()
            ->where('customer_email', $email)
            ->where('customer_phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $emailSentAt = $existing?->getRawOriginal('email_sent_at');
        $phoneSentAt = $existing?->getRawOriginal('phone_sent_at');
        if ($existing && ((is_string($emailSentAt) && CarbonImmutable::parse($emailSentAt)->gt(now()->subSeconds(60)))
            || (is_string($phoneSentAt) && CarbonImmutable::parse($phoneSentAt)->gt(now()->subSeconds(60))))) {
            throw ValidationException::withMessages(['verification' => __('public.verification_resend_wait')]);
        }

        $template = config('services.whatsapp.templates.contact_verification');
        if (! $emailOnly && (! is_string($template) || $template === '')) {
            throw new RuntimeException('The WhatsApp contact-verification template is not configured.');
        }
        $token = Str::random(64);
        $emailCode = (string) random_int(100000, 999999);
        $code = $emailOnly ? '' : (string) random_int(100000, 999999);
        $challenge = ContactVerificationChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'customer_email' => $email,
            'customer_phone' => $phone,
            'locale' => in_array($locale, ['ro', 'en'], true) ? $locale : 'ro',
            'return_to' => $returnTo === 'purchase' && $returnBundleId !== null ? 'purchase' : 'booking',
            'return_bundle_id' => $returnTo === 'purchase' ? $returnBundleId : null,
            'email_token_hash' => hash('sha256', $token),
            'email_code_hash' => hash_hmac('sha256', $emailCode, (string) config('app.key')),
            'email_attempts' => 0,
            'phone_code_hash' => hash_hmac('sha256', $code !== '' ? $code : Str::random(32), (string) config('app.key')),
            'expires_at' => now()->addMinutes(30),
            'phone_attempts' => 0,
        ]);

        $emailUrl = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(30), [
            'challenge' => $challenge->id,
            'token' => $token,
        ]);
        $subject = $challenge->locale === 'en' ? 'Verify your Easy English email' : 'Verifică adresa de e-mail Easy English';
        $body = $challenge->locale === 'en'
            ? "Your Easy English verification code is {$emailCode}.\n\nIt expires in 30 minutes. Enter it on the verification form, or use this backup link: {$emailUrl}\n\nDo not share this code. If you did not request it, you can ignore this message."
            : "Codul tău de verificare Easy English este {$emailCode}.\n\nExpiră în 30 de minute. Introdu-l în formularul de verificare sau folosește acest link de rezervă: {$emailUrl}\n\nNu comunica acest cod nimănui. Dacă nu ai solicitat verificarea, poți ignora acest mesaj.";

        try {
            Mail::raw($body, function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
            if (! $emailOnly) {
                $language = config('services.whatsapp.template_languages.'.$challenge->locale, $challenge->locale === 'en' ? 'en_US' : 'ro');
                $this->whatsapp->sendTemplate($phone, (string) $template, $language, [
                    [
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                    [
                        'type' => 'button',
                        // Native COPY_CODE authentication templates use the URL subtype when sent.
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ],
                ], 'contact-verification:'.$challenge->id);
            }
        } catch (\Throwable $exception) {
            // Invalidate a partially delivered challenge so a single channel cannot leave it usable.
            $challenge->forceFill([
                'email_token_hash' => hash('sha256', Str::random(64)),
                'email_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
                'phone_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
                'expires_at' => now(),
            ])->save();

            throw $exception;
        }
        $challenge->update(['email_sent_at' => now(), 'phone_sent_at' => $emailOnly ? null : now()]);

        return $challenge->refresh();
    }

    /** @param list<string> $visited */
    private function usesNonDeliveringMailer(string $name, array $visited = []): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }
        $visited[] = $name;
        $mailer = config('mail.mailers.'.$name, []);
        if (! is_array($mailer)) {
            return false;
        }
        if (in_array($mailer['transport'] ?? null, ['log', 'array'], true)) {
            return true;
        }
        foreach (($mailer['mailers'] ?? []) as $fallback) {
            if (is_string($fallback) && $this->usesNonDeliveringMailer($fallback, $visited)) {
                return true;
            }
        }

        return false;
    }

    public function confirmEmail(string $challengeId, string $token): ContactVerificationChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $token): array {
            $challenge = ContactVerificationChallenge::query()->whereKey($challengeId)->lockForUpdate()->firstOrFail();
            if (! $challenge->isUsable() || $challenge->email_verified_at !== null
                || ! hash_equals($challenge->email_token_hash, hash('sha256', $token))) {
                return ['error' => true];
            }
            $challenge->update([
                'email_verified_at' => now(),
                'email_token_hash' => hash('sha256', Str::random(64)),
            ]);

            return ['challenge' => $challenge->refresh()];
        });

        if (isset($result['error'])) {
            throw ValidationException::withMessages(['verification' => __('public.verification_email_invalid')]);
        }

        return $result['challenge'];
    }

    public function confirmEmailCode(string $challengeId, string $code): ContactVerificationChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $code): array {
            $challenge = ContactVerificationChallenge::query()->whereKey($challengeId)->lockForUpdate()->firstOrFail();
            if (! $challenge->isUsable() || $challenge->email_verified_at !== null) {
                return ['error' => 'invalid'];
            }
            if ($challenge->email_attempts >= 5) {
                return ['error' => 'locked'];
            }
            $expected = hash_hmac('sha256', $code, (string) config('app.key'));
            if (! hash_equals((string) $challenge->email_code_hash, $expected)) {
                $challenge->increment('email_attempts');
                return ['error' => $challenge->email_attempts >= 5 ? 'locked' : 'invalid'];
            }
            $challenge->update([
                'email_verified_at' => now(),
                'email_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
                'email_token_hash' => hash('sha256', Str::random(64)),
            ]);
            return ['challenge' => $challenge->refresh()];
        });
        if (isset($result['error'])) {
            $key = $result['error'] === 'locked' ? 'verification_email_locked' : 'verification_email_invalid_code';
            throw ValidationException::withMessages(['email_code' => __($key)]);
        }
        return $result['challenge'];
    }

    public function confirmPhone(string $challengeId, string $code): ContactVerificationChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $code): array {
            $challenge = ContactVerificationChallenge::query()->whereKey($challengeId)->lockForUpdate()->firstOrFail();
            if (! $challenge->isUsable() || $challenge->phone_verified_at !== null) {
                return ['error' => 'invalid'];
            }
            if ($challenge->phone_attempts >= 5) {
                return ['error' => 'locked'];
            }
            $expected = hash_hmac('sha256', $code, (string) config('app.key'));
            if (! hash_equals($challenge->phone_code_hash, $expected)) {
                $challenge->increment('phone_attempts');

                return ['error' => $challenge->phone_attempts >= 5 ? 'locked' : 'invalid'];
            }
            $challenge->update([
                'phone_verified_at' => now(),
                'phone_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
            ]);

            return ['challenge' => $challenge->refresh()];
        });

        if (isset($result['error'])) {
            $key = $result['error'] === 'locked' ? 'verification_phone_locked' : 'verification_phone_invalid';
            throw ValidationException::withMessages(['phone_code' => __($key)]);
        }

        return $result['challenge'];
    }
}
