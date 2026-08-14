<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarReconnectRequired;
use App\Exceptions\GoogleCalendarSafeFailure;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

class GoogleCalendarFailureClassifier
{
    /** @var list<string> */
    private const AUTH_REASONS = ['autherror', 'invalidcredentials', 'unauthorized'];

    /** @var list<string> */
    private const RATE_LIMIT_REASONS = ['ratelimitexceeded', 'userratelimitexceeded', 'quotaexceeded'];

    public function classify(Throwable $exception): GoogleCalendarFailure
    {
        if ($exception instanceof GoogleCalendarReconnectRequired) {
            return $this->reconnectRequired();
        }

        if ($exception instanceof GoogleCalendarSafeFailure) {
            return new GoogleCalendarFailure(
                $exception->failureCode,
                $exception->getMessage(),
                $exception->retryable,
                $exception->failureCode === 'reconnect_required',
            );
        }

        if ($exception instanceof GoogleServiceException) {
            $status = (int) $exception->getCode();
            $reasons = collect($exception->getErrors())
                ->map(fn ($error) => strtolower((string) ($error['reason'] ?? '')))
                ->filter()
                ->values()
                ->all();

            if ($status === 401 || array_intersect($reasons, self::AUTH_REASONS)) {
                return $this->reconnectRequired();
            }

            if ($status === 429 || array_intersect($reasons, self::RATE_LIMIT_REASONS)) {
                return new GoogleCalendarFailure(
                    'rate_limited',
                    'Google Calendar limitó temporalmente las solicitudes. Se reintentará automáticamente.',
                    true,
                );
            }

            if ($status >= 500) {
                return new GoogleCalendarFailure(
                    'google_unavailable',
                    'Google Calendar no está disponible temporalmente. Se reintentará automáticamente.',
                    true,
                );
            }

            if ($status === 403) {
                return new GoogleCalendarFailure(
                    'permission_denied',
                    'Google Calendar rechazó la operación. Revisa los permisos y la configuración de la cuenta.',
                    false,
                );
            }

            return new GoogleCalendarFailure(
                'google_request_rejected',
                'Google Calendar rechazó la solicitud. Revisa la configuración de la integración.',
                false,
            );
        }

        return new GoogleCalendarFailure(
            'temporary_failure',
            'No fue posible comunicarse correctamente con Google Calendar. Se reintentará automáticamente.',
            true,
        );
    }

    /** @param array<string, mixed> $response */
    public function classifyTokenResponse(array $response): GoogleCalendarFailure
    {
        $error = strtolower((string) ($response['error'] ?? ''));

        if ($error === 'invalid_grant') {
            return $this->reconnectRequired();
        }

        if ($error === 'temporarily_unavailable') {
            return new GoogleCalendarFailure(
                'google_unavailable',
                'Google no pudo renovar la autorización temporalmente. Se reintentará automáticamente.',
                true,
            );
        }

        return new GoogleCalendarFailure(
            'oauth_configuration_error',
            'Google rechazó la configuración OAuth. Revisa las credenciales autorizadas.',
            false,
        );
    }

    public function safeException(GoogleCalendarFailure $failure): GoogleCalendarSafeFailure
    {
        return new GoogleCalendarSafeFailure($failure->code, $failure->safeMessage, $failure->retryable);
    }

    private function reconnectRequired(): GoogleCalendarFailure
    {
        return new GoogleCalendarFailure(
            'reconnect_required',
            'Google revocó la autorización. Vuelve a vincular la cuenta.',
            false,
            true,
        );
    }
}
