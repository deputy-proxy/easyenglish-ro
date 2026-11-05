<?php

namespace App\Services\CallEvents;

use InvalidArgumentException;

final readonly class OpenWaConfig
{
    /** @param list<int> $retryDelaysSeconds */
    public function __construct(
        public bool $enabled,
        public ?string $baseUrl,
        public ?string $serviceToken,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxAttempts,
        public array $retryDelaysSeconds,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $enabled = (bool) ($values['enabled'] ?? false);
        $baseUrl = isset($values['base_url']) && is_string($values['base_url']) ? rtrim(trim($values['base_url']), '/') : null;
        $serviceToken = isset($values['service_token']) && is_string($values['service_token']) ? trim($values['service_token']) : null;
        $connectTimeout = (int) ($values['connect_timeout'] ?? 5);
        $timeout = (int) ($values['timeout'] ?? 30);
        $maxAttempts = (int) ($values['max_attempts'] ?? 5);
        $retryDelays = $values['retry_delays_seconds'] ?? [60, 300, 900, 3600, 14400];

        if ($enabled) {
            $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
            $validHttps = is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host']);
            $validPrivateHttp = is_array($parts) && ($parts['scheme'] ?? null) === 'http'
                && str_ends_with(strtolower((string) ($parts['host'] ?? '')), '.railway.internal');
            $hasUrlCredentialsOrQuery = is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']));
            if ((! $validHttps && ! $validPrivateHttp) || $hasUrlCredentialsOrQuery) {
                throw new InvalidArgumentException('OpenWA base URL must use HTTPS or the private Railway network hostname.');
            }
            if (! is_string($serviceToken) || mb_strlen($serviceToken) < 32) {
                throw new InvalidArgumentException('OpenWA service token must contain at least 32 characters.');
            }
        }

        if ($connectTimeout < 1 || $connectTimeout > 30 || $timeout < $connectTimeout || $timeout > 60) {
            throw new InvalidArgumentException('OpenWA HTTP timeouts must be between 1 and 60 seconds, with total timeout at least the connection timeout.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new InvalidArgumentException('OpenWA max attempts must be between 1 and 10.');
        }
        if (! is_array($retryDelays) || ! array_is_list($retryDelays) || $retryDelays === []) {
            throw new InvalidArgumentException('OpenWA retry delays must be a non-empty list of positive integers.');
        }
        $retryDelays = array_map(static function (mixed $delay): int {
            if ((! is_int($delay) && (! is_string($delay) || ! ctype_digit($delay))) || (int) $delay < 1 || (int) $delay > 86400) {
                throw new InvalidArgumentException('OpenWA retry delays must be positive integers no greater than one day.');
            }

            return (int) $delay;
        }, $retryDelays);

        return new self($enabled, $baseUrl, $serviceToken, $connectTimeout, $timeout, $maxAttempts, $retryDelays);
    }
}
