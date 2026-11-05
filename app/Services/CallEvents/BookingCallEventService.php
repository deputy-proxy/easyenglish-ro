<?php

namespace App\Services\CallEvents;

use App\Contracts\CallEvents\CallEventProvider;
use App\Data\CallEvents\CallEventResult;
use App\Exceptions\CallEvents\CallEventProviderException;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingCallEvent;
use App\Models\User;
use App\Services\Notifications\NotificationOutboxService;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingCallEventService
{
    public function __construct(
        private readonly CallEventProvider $provider,
        private readonly OpenWaConfig $config,
        private readonly NotificationOutboxService $notifications,
    ) {}

    public function createForBooking(int $bookingId, int $revision): ?BookingCallEvent
    {
        if (! $this->config->enabled) {
            return null;
        }
        if ($revision < 1 || $revision > 65535) {
            throw new \InvalidArgumentException('Call-event revision must be a positive integer.');
        }

        $claim = DB::transaction(function () use ($bookingId, $revision): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $event = BookingCallEvent::query()
                ->where('booking_id', $booking->id)
                ->where('provider', $this->provider->name())
                ->where('revision', $revision)
                ->lockForUpdate()
                ->first();

            $eligibleBooking = $booking->status === 'confirmed'
                && $booking->teacher_id !== null
                && $revision === $booking->reschedule_count + 1;
            if ($event === null) {
                if (! $eligibleBooking) {
                    return ['event' => null, 'claimed' => false];
                }

                $idempotencyKey = 'booking_call_event_'.substr(hash('sha256', $this->provider->name().':'.$booking->public_reference.':'.$revision), 0, 64);
                $event = BookingCallEvent::query()->create([
                    'booking_id' => $booking->id,
                    'teacher_id' => $booking->teacher_id,
                    'provider' => $this->provider->name(),
                    'revision' => $revision,
                    'idempotency_key' => $idempotencyKey,
                    'call_type' => 'video',
                    'scheduled_start_at' => $booking->starts_at->utc(),
                    'scheduled_end_at' => $booking->ends_at->utc(),
                    'status' => 'pending',
                ]);
            }

            $eventMatchesBooking = $eligibleBooking
                && $event->teacher_id === $booking->teacher_id
                && $event->scheduled_start_at->equalTo($booking->starts_at)
                && $event->scheduled_end_at->equalTo($booking->ends_at);

            if (! $eventMatchesBooking) {
                if (in_array($event->status, ['cancelled', 'cancelling'], true)) {
                    return ['event' => $event, 'claimed' => false];
                }
                if ($event->provider_event_id !== null) {
                    $event->update([
                        'status' => 'cancel_pending',
                        'cancel_requested_at' => $event->cancel_requested_at ?? now(),
                        'next_attempt_at' => now(),
                    ]);

                    return ['event' => $event->fresh(), 'claimed' => false];
                }
                if ($event->attempts > 0 || in_array($event->status, ['creating', 'reconciling', 'cancel_pending'], true)) {
                    $event->update([
                        'status' => 'cancel_pending',
                        'cancel_requested_at' => $event->cancel_requested_at ?? now(),
                        'next_attempt_at' => now(),
                    ]);
                    if (in_array($event->status, ['creating', 'reconciling'], true)) {
                        return ['event' => $event->fresh(), 'claimed' => false];
                    }
                } else {
                    $event->update([
                        'status' => 'cancelled',
                        'join_url' => null,
                        'cancelled_at' => now(),
                        'cancel_requested_at' => now(),
                        'next_attempt_at' => null,
                    ]);

                    return ['event' => $event->fresh(), 'claimed' => false];
                }
            }

            if (in_array($event->status, ['scheduled', 'cancelled', 'failed', 'creating', 'reconciling', 'cancelling'], true)
                || ($event->status === 'cancel_pending' && $event->provider_event_id !== null)
                || ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture())) {
                return ['event' => $event, 'claimed' => false];
            }

            $event->update([
                'status' => 'creating',
                'attempts' => $event->attempts + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'last_error_category' => null,
                'last_error_message' => null,
            ]);

            return ['event' => $event->fresh(), 'claimed' => true];
        }, attempts: 3);

        /** @var BookingCallEvent $event */
        $event = $claim['event'];
        if (! $claim['claimed']) {
            return $event;
        }

        $booking = $event->booking()->firstOrFail();
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $booking->customer_phone)) {
            return $this->scheduleRetry($event, 'failed', 'invalid_destination', 'Booking does not contain a valid E.164 customer phone number.', false);
        }
        if ($event->scheduled_start_at->lessThanOrEqualTo(now()->utc()->addMinutes(5))
            || $event->scheduled_end_at->lessThanOrEqualTo($event->scheduled_start_at)
            || $event->scheduled_end_at->diffInMinutes($event->scheduled_start_at) > 240) {
            return $this->scheduleRetry($event, 'failed', 'invalid_schedule', 'Booking call-event schedule is outside the supported time window.', false);
        }
        $payload = [
            'externalBookingReference' => $booking->public_reference,
            'destinationPhone' => $booking->customer_phone,
            'startAt' => $event->scheduled_start_at->utc()->toIso8601String(),
            'endAt' => $event->scheduled_end_at->utc()->toIso8601String(),
            'timezone' => 'Europe/Bucharest',
            'title' => 'EasyEnglish session',
            'callType' => $event->call_type,
        ];

        try {
            $result = $this->provider->create($payload, $event->idempotency_key);
            if ($result->status === 'pending') {
                if ($result->providerEventId !== null) {
                    $event->update(['provider_event_id' => $result->providerEventId]);
                    $retrieved = $this->provider->retrieve($result->providerEventId);
                    if ($retrieved !== null && $retrieved->status === 'scheduled') {
                        return $this->markScheduled($event, $retrieved);
                    }
                    if ($retrieved?->status === 'cancelled') {
                        $event->update(['status' => 'cancelled', 'join_url' => null, 'cancelled_at' => now()]);

                        return $event->fresh();
                    }
                }

                $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'reconciliation_pending';

                return $this->scheduleRetry($event, $retryStatus, 'provider_pending', 'Provider has not confirmed the scheduled event yet.', true);
            }

            return $this->markScheduled($event, $result);
        } catch (CallEventProviderException $exception) {
            $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'pending';

            return $this->recordFailure($event, $exception, $retryStatus);
        } catch (Throwable $exception) {
            Log::warning('Booking call-event creation failed.', [
                'booking_call_event_id' => $event->id,
                'exception' => $exception::class,
            ]);
            $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'pending';

            return $this->scheduleRetry($event, $retryStatus, 'internal_error', 'Call-event creation failed unexpectedly.', true);
        }
    }

    public function retrieve(int $eventId): ?BookingCallEvent
    {
        if (! $this->config->enabled) {
            return null;
        }

        $claim = DB::transaction(function () use ($eventId): array {
            $event = BookingCallEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->provider_event_id === null
                || in_array($event->status, ['scheduled', 'cancelled', 'failed', 'creating', 'reconciling', 'cancelling', 'cancel_pending'], true)
                || ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture())) {
                return ['event' => $event, 'claimed' => false];
            }
            $event->update(['status' => 'reconciling', 'attempts' => $event->attempts + 1, 'last_attempt_at' => now(), 'next_attempt_at' => null]);

            return ['event' => $event->fresh(), 'claimed' => true];
        }, attempts: 3);

        /** @var BookingCallEvent|null $event */
        $event = $claim['event'];
        if (! $claim['claimed'] || $event === null) {
            return $event;
        }

        try {
            $result = $this->provider->retrieve((string) $event->provider_event_id);
            if ($result === null) {
                $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'reconciliation_pending';

                return $this->scheduleRetry($event, $retryStatus, 'event_not_found', 'Provider event is not available for reconciliation.', false);
            }
            if ($result->status === 'cancelled') {
                $event->update(['status' => 'cancelled', 'join_url' => null, 'cancelled_at' => now(), 'next_attempt_at' => null]);

                return $event->fresh();
            }
            if ($result->status === 'pending') {
                $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'reconciliation_pending';

                return $this->scheduleRetry($event, $retryStatus, 'provider_pending', 'Provider has not confirmed the scheduled event yet.', true);
            }

            return $this->markScheduled($event, $result);
        } catch (CallEventProviderException $exception) {
            $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'reconciliation_pending';

            return $this->recordFailure($event, $exception, $retryStatus);
        } catch (Throwable $exception) {
            Log::warning('Booking call-event reconciliation failed.', [
                'booking_call_event_id' => $event->id,
                'exception' => $exception::class,
            ]);

            $retryStatus = $event->fresh()->cancel_requested_at !== null ? 'cancel_pending' : 'reconciliation_pending';

            return $this->scheduleRetry($event, $retryStatus, 'internal_error', 'Call-event reconciliation failed unexpectedly.', true);
        }
    }

    public function cancel(int $eventId): ?BookingCallEvent
    {
        if (! $this->config->enabled) {
            return null;
        }

        $claim = DB::transaction(function () use ($eventId): array {
            $event = BookingCallEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->status === 'cancelled' || $event->status === 'cancelling') {
                return ['event' => $event, 'claimed' => false];
            }
            if (in_array($event->status, ['creating', 'reconciling'], true)) {
                $event->update(['status' => 'cancel_pending', 'cancel_requested_at' => now(), 'next_attempt_at' => now()]);

                return ['event' => $event->fresh(), 'claimed' => false];
            }
            if ($event->status === 'cancel_pending' && $event->next_attempt_at !== null && $event->next_attempt_at->isFuture()) {
                return ['event' => $event, 'claimed' => false];
            }
            if ($event->provider_event_id === null) {
                if (in_array($event->status, ['reconciliation_pending', 'cancel_pending'], true) || $event->attempts > 0) {
                    $event->update(['status' => 'cancel_pending', 'cancel_requested_at' => $event->cancel_requested_at ?? now(), 'next_attempt_at' => now()]);
                } else {
                    $event->update(['status' => 'cancelled', 'join_url' => null, 'cancelled_at' => now(), 'next_attempt_at' => null]);
                }

                return ['event' => $event->fresh(), 'claimed' => false];
            }
            $event->update(['status' => 'cancelling', 'cancel_requested_at' => $event->cancel_requested_at ?? now(), 'attempts' => $event->attempts + 1, 'last_attempt_at' => now(), 'next_attempt_at' => null]);

            return ['event' => $event->fresh(), 'claimed' => true];
        }, attempts: 3);

        /** @var BookingCallEvent|null $event */
        $event = $claim['event'];
        if (! $claim['claimed'] || $event === null) {
            return $event;
        }

        try {
            if (! $this->provider->cancel((string) $event->provider_event_id)) {
                return $this->scheduleRetry($event, 'cancel_pending', 'provider_not_confirmed', 'Provider did not confirm event cancellation.', false);
            }
            $event->update(['status' => 'cancelled', 'join_url' => null, 'cancelled_at' => now(), 'next_attempt_at' => null, 'last_error_category' => null, 'last_error_message' => null]);

            return $event->fresh();
        } catch (CallEventProviderException $exception) {
            return $this->recordFailure($event, $exception, 'cancel_pending');
        } catch (Throwable $exception) {
            Log::warning('Booking call-event cancellation failed.', [
                'booking_call_event_id' => $event->id,
                'exception' => $exception::class,
            ]);

            return $this->scheduleRetry($event, 'cancel_pending', 'internal_error', 'Call-event cancellation failed unexpectedly.', true);
        }
    }

    public function isReady(): bool
    {
        return $this->config->enabled && $this->provider->isReady();
    }

    private function markScheduled(BookingCallEvent $event, CallEventResult $result): BookingCallEvent
    {
        if ($result->status !== 'scheduled' || ! is_string($result->providerEventId) || ! preg_match('/^[A-Za-z0-9_.@:-]{8,160}$/', $result->providerEventId) || ! $this->isValidJoinUrl($result->joinUrl)) {
            return $this->scheduleRetry($event, 'failed', 'invalid_response', 'Provider did not return a valid event ID and HTTPS call link.', false);
        }

        $resultState = DB::transaction(function () use ($event, $result): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($event->booking_id);
            $lockedEvent = BookingCallEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (in_array($lockedEvent->status, ['cancelled', 'cancelling'], true)) {
                return ['cancel' => false];
            }

            $cancelRequested = $lockedEvent->cancel_requested_at !== null;
            $isCurrent = $booking->status === 'confirmed'
                && $booking->teacher_id !== null
                && $lockedEvent->revision === $booking->reschedule_count + 1
                && $lockedEvent->teacher_id === $booking->teacher_id
                && $lockedEvent->scheduled_start_at->equalTo($booking->starts_at)
                && $lockedEvent->scheduled_end_at->equalTo($booking->ends_at);
            if (! $isCurrent) {
                $cancelRequested = true;
            }

            $lockedEvent->update([
                'provider_event_id' => $result->providerEventId,
                'join_url' => $cancelRequested ? null : $result->joinUrl,
                'status' => $cancelRequested ? 'cancel_pending' : 'scheduled',
                'provider_created_at' => $result->createdAt ?? now(),
                'scheduled_at' => $cancelRequested ? null : now(),
                'cancel_requested_at' => $cancelRequested ? ($lockedEvent->cancel_requested_at ?? now()) : null,
                'next_attempt_at' => $cancelRequested ? now() : null,
                'last_error_category' => null,
                'last_error_message' => null,
            ]);

            if (! $cancelRequested) {
                $this->notifications->enqueueCallLinkReady($booking, $lockedEvent->fresh());
            }

            return ['cancel' => $cancelRequested];
        }, attempts: 3);
        $event->refresh();

        return $resultState['cancel'] ? ($this->cancel($event->id) ?? $event) : $event;
    }

    private function recordFailure(BookingCallEvent $event, CallEventProviderException $exception, string $retryStatus = 'pending'): BookingCallEvent
    {
        Log::warning('Booking call-event provider operation failed.', [
            'booking_call_event_id' => $event->id,
            'provider' => $event->provider,
            'category' => $exception->category,
            'attempt' => $event->attempts,
            'retryable' => $exception->retryable,
        ]);

        return $this->scheduleRetry($event, $retryStatus, $exception->category, $this->safeErrorMessage($exception->category), $exception->retryable);
    }

    private function scheduleRetry(BookingCallEvent $event, string $retryStatus, string $category, string $message, bool $retryable): BookingCallEvent
    {
        return DB::transaction(function () use ($event, $retryStatus, $category, $message, $retryable): BookingCallEvent {
            $lockedEvent = BookingCallEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($lockedEvent->status === 'cancelled' || ($lockedEvent->status === 'scheduled' && $lockedEvent->cancel_requested_at === null)
                || ($lockedEvent->status === 'failed' && $lockedEvent->cancel_requested_at === null)) {
                return $lockedEvent;
            }

            if ($lockedEvent->cancel_requested_at !== null) {
                $retryStatus = 'cancel_pending';
            }
            $attempts = $lockedEvent->attempts;
            $wasFailed = $lockedEvent->status === 'failed';
            $canRetry = $retryable && $attempts < $this->config->maxAttempts;
            $delayIndex = min(max($attempts - 1, 0), count($this->config->retryDelaysSeconds) - 1);
            $lockedEvent->update([
                'status' => $canRetry ? $retryStatus : 'failed',
                'next_attempt_at' => $canRetry ? now()->addSeconds($this->config->retryDelaysSeconds[$delayIndex]) : null,
                'last_error_category' => mb_substr($category, 0, 64),
                'last_error_message' => mb_substr($message, 0, 255),
            ]);

            if (! $canRetry && ! $wasFailed && $lockedEvent->status === 'failed') {
                BookingAudit::query()->create([
                    'booking_id' => $lockedEvent->booking_id,
                    'subject_type' => 'booking_call_event',
                    'subject_id' => $lockedEvent->id,
                    'action' => 'call_event.failed',
                    'reason' => $this->safeErrorMessage($category),
                    'metadata' => ['revision' => $lockedEvent->revision, 'category' => $category],
                ]);
                User::query()->where('is_staff', true)->get()->each(static function (User $staff): void {
                    FilamentNotification::make()
                        ->title('WhatsApp call setup needs attention')
                        ->body('A scheduled call could not be prepared after automatic retries. Review the Call events page and retry or resolve the provider issue.')
                        ->danger()
                        ->sendToDatabase($staff);
                });
            }

            return $lockedEvent->fresh();
        }, attempts: 3);
    }

    private function safeErrorMessage(string $category): string
    {
        return match ($category) {
            'authentication' => 'Call-event provider authentication failed.',
            'invalid_request' => 'Call-event provider rejected the request.',
            'conflict' => 'Call-event provider reported a conflict.',
            'rate_limited' => 'Call-event provider rate limit was reached.',
            'unavailable' => 'Call-event provider is unavailable.',
            'timeout' => 'Call-event provider request timed out.',
            'transport_error' => 'Call-event provider transport failed.',
            'invalid_response' => 'Call-event provider returned an invalid response.',
            'provider_error' => 'Call-event provider operation failed.',
            default => 'Call-event provider operation failed.',
        };
    }

    private function isValidJoinUrl(?string $url): bool
    {
        if (! is_string($url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'call.whatsapp.com'
            && isset($parts['path'])
            && $parts['path'] !== '/'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }
}
