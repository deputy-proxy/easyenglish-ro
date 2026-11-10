<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppDeliveryRejectedException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $errorCode)
    {
        $this->errorCode = preg_match('/^[A-Z0-9_]{1,64}$/', $errorCode) === 1
            ? $errorCode
            : 'OPENWA_REJECTED';

        parent::__construct('OpenWA rejected the message ('.$this->errorCode.').');
    }
}
