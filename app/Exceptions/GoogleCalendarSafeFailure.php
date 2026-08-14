<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarSafeFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $safeMessage,
        public readonly bool $retryable,
    ) {
        parent::__construct($safeMessage);
    }
}
