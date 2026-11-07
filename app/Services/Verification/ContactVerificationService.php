<?php

namespace App\Services\Verification;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Models\ContactVerificationChallenge;
use App\Services\Notifications\WhatsAppProviderConfiguration;
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

    public function start(
        string $email,
        string $phone,
        string $locale,
        string $returnTo = 'booking',
        ?int $returnBundleId = null,
        bool $emailAlreadyVerified = false,
        bool $phoneAlreadyVerified = false,
    ): ContactVerificationChallenge {
        ContactVerificationChallenge::query()->where('expires_at', '<', now()->subDay())->delete();
        $email = mb_strtolower(trim($email));
        $phone = PhoneNumber::normalize($phone);
        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        $verifyEmail = ! $emailAlreadyVerified;
        $verifyPhone = ! $emailOnly && ! $phoneAlreadyVerified;

        if ($verifyPhone && ! $this->whatsappProviderConfigured()) {
            throw new RuntimeException('WhatsApp verification delivery is not configured for the selected provider.');
        }
        if ($verifyEmail && ! app()->environment('testing') && $this->usesNonDeliveringMailer((string) config('mail.default'))) {
            throw new RuntimeException('Email verification requires a real delivery transport; log/array transports can expose or discard verification secrets.');
        }

        $accountLimitKey = 'contact-verification:account:'.hash('sha256', $email);
        $emailLimitKey = 'contact-verification:email:'.hash('sha256', $email);
        $phoneLimitKey = 'contact-verification:phone:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($accountLimitKey, 5)
            || ($verifyEmail && RateLimiter::tooManyAttempts($emailLimitKey, 5))
            || ($verifyPhone && RateLimiter::tooManyAttempts($phoneLimitKey, 5))) {
            throw ValidationException::withMessages(['verification' => __('public.verification_rate_limited')]);
        }

        $existing = ContactVerificationChallenge::query()
            ->where('customer_email', $email)
            ->where('customer_phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $emailSentAt = $existing?->getRawOriginal('email_sent_at');
        $phoneSentAt = $existing?->getRawOriginal('phone_sent_at');
        if ($existing && (($verifyEmail && is_string($emailSentAt) && CarbonImmutable::parse($emailSentAt)->gt(now()->subSeconds(60)))
            || ($verifyPhone && is_string($phoneSentAt) && CarbonImmutable::parse($phoneSentAt)->gt(now()->subSeconds(60))))) {
            throw ValidationException::withMessages(['verification' => __('public.verification_resend_wait')]);
        }

        $template = config('services.whatsapp.templates.contact_verification');
        if ($verifyPhone && (! is_string($template) || $template === '')) {
            throw new RuntimeException('The WhatsApp contact-verification template is not configured.');
        }

        RateLimiter::hit($accountLimitKey, 3600);
        if ($verifyEmail) {
            RateLimiter::hit($emailLimitKey, 3600);
        }
        if ($verifyPhone) {
            RateLimiter::hit($phoneLimitKey, 3600);
        }

        ContactVerificationChallenge::query()
            ->where('customer_email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $token = Str::random(64);
        $emailCode = (string) random_int(100000, 999999);
        $phoneCode = (string) random_int(100000, 999999);
        $challenge = ContactVerificationChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'customer_email' => $email,
            'customer_phone' => $phone,
            'locale' => in_array($locale, ['ro', 'en'], true) ? $locale : 'ro',
            'return_to' => $returnTo === 'purchase' && $returnBundleId !== null ? 'purchase' : 'booking',
            'return_bundle_id' => $returnTo === 'purchase' ? $returnBundleId : null,
            'email_token_hash' => hash('sha256', $verifyEmail ? $token : Str::random(64)),
            'email_code_hash' => hash_hmac('sha256', $verifyEmail ? $emailCode : Str::random(32), (string) config('app.key')),
            'email_verified_at' => $emailAlreadyVerified ? now() : null,
            'email_attempts' => 0,
            'phone_code_hash' => hash_hmac('sha256', $verifyPhone ? $phoneCode : Str::random(32), (string) config('app.key')),
            'phone_verified_at' => $phoneAlreadyVerified ? now() : null,
            'expires_at' => now()->addMinutes(30),
            'phone_attempts' => 0,
        ]);

        try {
            if ($verifyEmail) {
                $emailUrl = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(30), [
                    'challenge' => $challenge->id,
                    'token' => $token,
                ]);
                $subject = $challenge->locale === 'en' ? 'Verify your Easy English email' : 'Verifică adresa de e-mail Easy English';
                $body = $challenge->locale === 'en'
                    ? "Your Easy English verification code is {$emailCode}.\n\nIt expires in 30 minutes. Enter it on the verification form, or use this backup link: {$emailUrl}\n\nDo not share this code. If you did not request it, you can ignore this message."
                    : "Codul tău de verificare Easy English este {$emailCode}.\n\nExpiră în 30 de minute. Introdu-l în formularul de verificare sau folosește acest link de rezervă: {$emailUrl}\n\nNu comunica acest cod nimănui. Dacă nu ai solicitat verificarea, poți ignora acest mesaj.";

                Mail::raw($body, function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            }

            if ($verifyPhone) {
                $language = config('services.whatsapp.contact_verification_template_languages.'.$challenge->locale, $challenge->locale === 'en' ? 'en' : 'ro');
                $this->whatsapp->sendTemplate(new WhatsAppTemplateMessage(
                    $phone,
                    'contact_verification',
                    $language,
                    [$phoneCode],
                    [['index' => '0', 'parameters' => [$phoneCode]]],
                    'contact-verification-'.$challenge->id,
                ));
            }
        } catch (\Throwable $exception) {
            $challenge->forceFill([
                'email_token_hash' => hash('sha256', Str::random(64)),
                'email_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
                'phone_code_hash' => hash_hmac('sha256', Str::random(32), (string) config('app.key')),
                'expires_at' => now(),
            ])->save();

            throw $exception;
        }

        $challenge->update([
            'email_sent_at' => $verifyEmail ? now() : null,
            'phone_sent_at' => $verifyPhone ? now() : null,
        ]);

        return $challenge->refresh();
    }

    private function whatsappProviderConfigured(): bool
    {
        return app(WhatsAppProviderConfiguration::class)->isOutboundConfigured();
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
            if ($challenge->consumed_at !== null) {
                return ['error' => 'used'];
            }
            if (CarbonImmutable::parse($challenge->getRawOriginal('expires_at'))->isPast()) {
                return ['error' => 'expired'];
            }
            if ($challenge->email_verified_at !== null) {
                return ['error' => 'already_verified'];
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
            $key = match ($result['error']) {
                'locked' => 'verification_email_locked',
                'expired' => 'verification_challenge_expired',
                'used' => 'verification_challenge_used',
                'already_verified' => 'verification_channel_already_verified',
                default => 'verification_email_invalid_code',
            };
            $field = in_array($result['error'], ['expired', 'used', 'already_verified'], true) ? 'challenge' : 'email_code';
            throw ValidationException::withMessages([$field => __('public.'.$key)]);
        }

        return $result['challenge'];
    }

    public function confirmPhone(string $challengeId, string $code): ContactVerificationChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $code): array {
            $challenge = ContactVerificationChallenge::query()->whereKey($challengeId)->lockForUpdate()->firstOrFail();
            if ($challenge->consumed_at !== null) {
                return ['error' => 'used'];
            }
            if (CarbonImmutable::parse($challenge->getRawOriginal('expires_at'))->isPast()) {
                return ['error' => 'expired'];
            }
            if ($challenge->phone_verified_at !== null) {
                return ['error' => 'already_verified'];
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
            $key = match ($result['error']) {
                'locked' => 'verification_phone_locked',
                'expired' => 'verification_challenge_expired',
                'used' => 'verification_challenge_used',
                'already_verified' => 'verification_channel_already_verified',
                default => 'verification_phone_invalid',
            };
            $field = in_array($result['error'], ['expired', 'used', 'already_verified'], true) ? 'challenge' : 'phone_code';
            throw ValidationException::withMessages([$field => __('public.'.$key)]);
        }

        return $result['challenge'];
    }
}
