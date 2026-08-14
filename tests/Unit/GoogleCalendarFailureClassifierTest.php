<?php

namespace Tests\Unit;

use App\Services\GoogleCalendarFailureClassifier;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleCalendarFailureClassifierTest extends TestCase
{
    #[DataProvider('googleFailures')]
    public function test_google_failures_are_classified_without_persisting_provider_messages(
        int $status,
        string $reason,
        string $expectedCode,
        bool $retryable,
        bool $reconnect,
    ): void {
        $failure = (new GoogleCalendarFailureClassifier)->classify(new GoogleServiceException(
            'access_token=secret client_secret=secret',
            $status,
            null,
            $reason ? [['reason' => $reason]] : [],
        ));

        $this->assertSame($expectedCode, $failure->code);
        $this->assertSame($retryable, $failure->retryable);
        $this->assertSame($reconnect, $failure->requiresReconnect);
        $this->assertStringNotContainsString('secret', $failure->safeMessage);
    }

    public static function googleFailures(): array
    {
        return [
            '401' => [401, '', 'reconnect_required', false, true],
            'authError' => [403, 'authError', 'reconnect_required', false, true],
            'quota' => [403, 'quotaExceeded', 'rate_limited', true, false],
            '429' => [429, '', 'rate_limited', true, false],
            'server' => [503, '', 'google_unavailable', true, false],
            'permission' => [403, 'forbidden', 'permission_denied', false, false],
        ];
    }

    public function test_invalid_grant_requires_reconnection_without_using_description(): void
    {
        $failure = (new GoogleCalendarFailureClassifier)->classifyTokenResponse([
            'error' => 'invalid_grant',
            'error_description' => 'refresh_token=secret',
        ]);

        $this->assertTrue($failure->requiresReconnect);
        $this->assertSame('reconnect_required', $failure->code);
        $this->assertStringNotContainsString('secret', $failure->safeMessage);
    }
}
