<?php

namespace App\Services;

final readonly class GoogleCalendarFailure
{
    public function __construct(
        public string $code,
        public string $safeMessage,
        public bool $retryable,
        public bool $requiresReconnect = false,
    ) {}
}
