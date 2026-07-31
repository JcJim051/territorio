<?php

namespace App\Services;

use App\Models\CalendarConnection;
use Google\Service\Calendar\Channel;
use Illuminate\Support\Str;

class GoogleCalendarWatch
{
    public function __construct(private readonly GoogleCalendarClientFactory $factory)
    {
    }

    public function renew(CalendarConnection $connection): void
    {
        $configuration = $this->factory->configurationForCampaign($connection->campaign_id);
        $webhookUrl = (string) ($configuration->settings['webhook_url'] ?? '');
        if (! $connection->isReady() || ! str_starts_with($webhookUrl, 'https://')) {
            return;
        }

        $service = $this->factory->service($connection);
        if ($connection->watch_channel_id && $connection->watch_resource_id) {
            try {
                $service->channels->stop(new Channel([
                    'id' => $connection->watch_channel_id,
                    'resourceId' => $connection->watch_resource_id,
                ]));
            } catch (\Throwable) {
                // Google can expire a channel before renewal; creating a new one remains safe.
            }
        }

        $id = (string) Str::uuid();
        $token = Str::random(64);
        $expiration = now()->addDays(6);
        $channel = new Channel([
            'id' => $id,
            'type' => 'web_hook',
            'address' => $webhookUrl,
            'token' => $token,
            'expiration' => (string) ($expiration->getTimestampMs()),
        ]);
        $watch = $service->events->watch($connection->calendar_id, $channel);

        $connection->forceFill([
            'watch_channel_id' => $id,
            'watch_resource_id' => $watch->getResourceId(),
            'watch_token_hash' => hash('sha256', $token),
            'watch_expires_at' => $watch->getExpiration()
                ? now()->setTimestamp((int) floor(((int) $watch->getExpiration()) / 1000))
                : $expiration,
        ])->save();
    }
}
