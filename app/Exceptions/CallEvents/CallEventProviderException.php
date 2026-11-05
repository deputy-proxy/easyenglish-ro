<?php

namespace App\Exceptions\CallEvents;

use RuntimeException;

class CallEventProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable,
        string $message,
    ) {
        parent::__construct($message);
    }
}
