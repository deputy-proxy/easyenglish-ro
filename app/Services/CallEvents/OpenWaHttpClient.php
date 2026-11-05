<?php

namespace App\Services\CallEvents;

use App\Data\CallEvents\CallEventResult;
use App\Exceptions\CallEvents\CallEventProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenWaHttpClient
{
    public function __construct(private readonly OpenWaConfig $config) {}

    public function isReady(): bool
    {
        if (! $this->config->enabled) {
            return false;
        }

        try {
            $response = $this->request()->get($this->url('/health/ready'));

            return $response->successful() && $response->json('status') === 'ready';
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, string> $payload */
    public function createEvent(array $payload, string $idempotencyKey): CallEventResult
    {
        $response = $this->send(fn (): Response => $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post($this->url('/v1/call-events'), $payload));

        if ($response->status() === 202) {
            return $this->parseResult($response, allowPending: true);
        }

        $this->assertSuccessful($response);

        return $this->parseResult($response);
    }

    public function retrieveEvent(string $providerEventId): ?CallEventResult
    {
        $response = $this->send(fn (): Response => $this->request()->get($this->url('/v1/call-events/'.rawurlencode($providerEventId))));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccessful($response);

        return $this->parseResult($response, allowPending: true, allowCancelled: true);
    }

    public function cancelEvent(string $providerEventId): bool
    {
        $response = $this->send(fn (): Response => $this->request()->delete($this->url('/v1/call-events/'.rawurlencode($providerEventId))));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccessful($response);
        if ($response->json('providerEventId') !== $providerEventId || $response->json('status') !== 'cancelled') {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid cancellation response.');
        }

        return true;
    }

    private function request(): PendingRequest
    {
        if (! $this->config->enabled || $this->config->baseUrl === null || $this->config->serviceToken === null) {
            throw new CallEventProviderException('disabled', false, 'OpenWA integration is disabled or not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($this->config->serviceToken)
            ->connectTimeout($this->config->connectTimeoutSeconds)
            ->timeout($this->config->timeoutSeconds)
            ->retry(0);
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new CallEventProviderException('timeout', true, 'OpenWA service connection timed out.');
        } catch (CallEventProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CallEventProviderException('transport_error', true, 'OpenWA service request failed.');
        }
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        [$category, $retryable, $message] = match (true) {
            in_array($response->status(), [401, 403], true) => ['authentication', false, 'OpenWA service authentication failed.'],
            $response->status() === 400 => ['invalid_request', false, 'OpenWA rejected the call-event request.'],
            $response->status() === 409 => ['conflict', false, 'OpenWA reported a call-event conflict.'],
            in_array($response->status(), [408, 425], true) => ['timeout', true, 'OpenWA service request timed out.'],
            $response->status() === 429 => ['rate_limited', true, 'OpenWA service rate limit was reached.'],
            $response->status() === 503 => ['unavailable', true, 'OpenWA service is unavailable.'],
            $response->serverError() => ['provider_error', true, 'OpenWA service returned a provider error.'],
            default => ['http_error', false, 'OpenWA service returned an unexpected response.'],
        };

        throw new CallEventProviderException($category, $retryable, $message);
    }

    private function parseResult(Response $response, bool $allowPending = false, bool $allowCancelled = false): CallEventResult
    {
        $providerEventId = $response->json('providerEventId');
        $joinUrl = $response->json('joinUrl');
        $status = $response->json('status');
        $createdAt = $response->json('createdAt');

        if (! is_string($status) || ! in_array($status, ['scheduled', 'cancelled', 'creating', 'submission_unconfirmed'], true)) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an unsupported call-event status.');
        }
        if (in_array($status, ['submission_unconfirmed', 'creating'], true) && ! $allowPending) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an incomplete call-event result.');
        }
        if ($status === 'cancelled' && ! $allowCancelled) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned a cancelled event during creation.');
        }
        if ($providerEventId !== null && (! is_string($providerEventId) || ! preg_match('/^[A-Za-z0-9_.@:-]{8,160}$/', $providerEventId))) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid provider event ID.');
        }
        if ($status === 'scheduled' && ($providerEventId === null)) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA did not return a valid provider event ID.');
        }
        if ($status === 'scheduled' && $joinUrl === null) {
            $status = 'pending';
        }
        if ($status === 'scheduled' && (! is_string($joinUrl) || ! $this->isValidJoinUrl($joinUrl))) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA did not return a valid HTTPS call link.');
        }
        if ($joinUrl !== null && (! is_string($joinUrl) || ! $this->isValidJoinUrl($joinUrl))) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid call link.');
        }

        $parsedCreatedAt = null;
        if ($createdAt !== null) {
            if (! is_string($createdAt) || trim($createdAt) === '') {
                throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid creation timestamp.');
            }
            try {
                $parsedCreatedAt = CarbonImmutable::parse($createdAt)->utc();
            } catch (Throwable) {
                throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid creation timestamp.');
            }
        } elseif (! in_array($status, ['submission_unconfirmed', 'creating'], true)) {
            throw new CallEventProviderException('invalid_response', false, 'OpenWA returned an invalid creation timestamp.');
        }

        if ($status === 'submission_unconfirmed' || $status === 'creating') {
            $status = 'pending';
        }

        return new CallEventResult($status, $providerEventId, $joinUrl, $parsedCreatedAt);
    }

    private function isValidJoinUrl(string $url): bool
    {
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

    private function url(string $path): string
    {
        return rtrim((string) $this->config->baseUrl, '/').$path;
    }
}
