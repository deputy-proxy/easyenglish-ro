<?php

namespace Tests\Fakes;

use App\Contracts\CallEvents\CallEventProvider;
use App\Data\CallEvents\CallEventResult;
use App\Exceptions\CallEvents\CallEventProviderException;

class FakeCallEventProvider implements CallEventProvider
{
    /** @var array<string, CallEventResult> */
    public array $events = [];

    /** @var list<array{payload: array<string, string>, idempotency_key: string}> */
    public array $createRequests = [];

    /** @var list<string> */
    public array $retrieveRequests = [];

    /** @var list<string> */
    public array $cancelRequests = [];

    public ?CallEventProviderException $nextCreateException = null;

    public bool $createThenThrowOnce = false;

    public ?CallEventResult $nextCreateResult = null;

    public ?CallEventResult $nextRetrieveResult = null;

    public bool $ready = true;

    public function name(): string
    {
        return 'openwa';
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function create(array $payload, string $idempotencyKey): CallEventResult
    {
        $this->createRequests[] = ['payload' => $payload, 'idempotency_key' => $idempotencyKey];
        if ($this->nextCreateException !== null) {
            $exception = $this->nextCreateException;
            $this->nextCreateException = null;
            throw $exception;
        }
        if (isset($this->events[$idempotencyKey])) {
            return $this->events[$idempotencyKey];
        }
        if ($this->nextCreateResult !== null) {
            $result = $this->nextCreateResult;
            $this->nextCreateResult = null;
            if ($result->providerEventId !== null) {
                $this->events[$idempotencyKey] = $result;
            }

            return $result;
        }

        $providerEventId = 'fake-event-'.(count($this->events) + 1).'-'.str_repeat('x', 12);
        $result = new CallEventResult('scheduled', $providerEventId, 'https://call.whatsapp.com/fake-call-link', now()->toImmutable());
        $this->events[$idempotencyKey] = $result;
        if ($this->createThenThrowOnce) {
            $this->createThenThrowOnce = false;
            throw new CallEventProviderException('timeout', true, 'OpenWA service connection timed out.');
        }

        return $result;
    }

    public function retrieve(string $providerEventId): ?CallEventResult
    {
        $this->retrieveRequests[] = $providerEventId;
        if ($this->nextRetrieveResult !== null) {
            $result = $this->nextRetrieveResult;
            $this->nextRetrieveResult = null;

            return $result;
        }
        foreach ($this->events as $event) {
            if ($event->providerEventId === $providerEventId) {
                return $event;
            }
        }

        return null;
    }

    public function cancel(string $providerEventId): bool
    {
        $this->cancelRequests[] = $providerEventId;

        foreach ($this->events as $key => $event) {
            if ($event->providerEventId === $providerEventId) {
                $this->events[$key] = new CallEventResult('cancelled', $providerEventId, null, $event->createdAt);

                return true;
            }
        }

        return false;
    }
}
