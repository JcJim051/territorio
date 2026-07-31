<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\Campaign;
use App\Models\CampaignServiceCredential;
use Google\Client;
use Google\Service\Calendar;
use RuntimeException;

class GoogleCalendarClientFactory
{
    public function client(?CalendarConnection $connection = null, ?Campaign $campaign = null): Client
    {
        $campaignId = $connection?->campaign_id ?: $campaign?->id;
        if (! $campaignId) {
            throw new RuntimeException('No se pudo determinar la campaña para configurar Google Calendar.');
        }
        $configuration = $this->configurationForCampaign($campaignId);
        $credentials = $configuration->credentials;
        $client = new Client();
        $client->setClientId((string) ($credentials['client_id'] ?? ''));
        $client->setClientSecret((string) ($credentials['client_secret'] ?? ''));
        $client->setRedirectUri((string) ($configuration->settings['redirect_uri'] ?? ''));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes([
            'openid',
            'email',
            Calendar::CALENDAR_EVENTS,
            Calendar::CALENDAR_CALENDARLIST_READONLY,
        ]);

        if ($connection && $connection->access_token) {
            $client->setAccessToken($connection->access_token);
            if ($client->isAccessTokenExpired()) {
                if (! $connection->refresh_token) {
                    throw new RuntimeException('Google revocó la sesión. Es necesario volver a vincular la cuenta.');
                }
                $token = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);
                if (isset($token['error'])) {
                    throw new RuntimeException($token['error_description'] ?? $token['error']);
                }
                $connection->forceFill([
                    'access_token' => $token,
                    'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600) - 60)),
                    'last_error' => null,
                ])->save();
            }
        }

        return $client;
    }

    public function service(CalendarConnection $connection): Calendar
    {
        return new Calendar($this->client($connection));
    }

    public function configurationForCampaign(int $campaignId): CampaignServiceCredential
    {
        $configuration = CampaignServiceCredential::query()
            ->where('campaign_id', $campaignId)
            ->where('provider', 'google_calendar')
            ->first();

        if (
            ! $configuration
            || blank($configuration->credentials['client_id'] ?? null)
            || blank($configuration->credentials['client_secret'] ?? null)
            || blank($configuration->settings['redirect_uri'] ?? null)
        ) {
            throw new RuntimeException('Completa la configuración OAuth de Google para esta campaña.');
        }

        return $configuration;
    }
}
