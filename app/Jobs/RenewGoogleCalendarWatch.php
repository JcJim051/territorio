<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarWatch;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenewGoogleCalendarWatch implements ShouldQueue
{
    use Queueable;

    public ?int $campaignId = null;

    public int $connectionId;

    public int $tries = 5;

    public int $timeout = 75;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        int $campaignId,
        int $connectionId,
    ) {
        $this->campaignId = $campaignId;
        $this->connectionId = $connectionId;
    }

    public function handle(
        GoogleCalendarWatch $watch,
        GoogleCalendarFailureClassifier $failureClassifier,
        CampaignContextResolver $resolver,
        AuthorizedContextRunner $runner,
    ): void {
        $context = $this->campaignId === null
            ? $resolver->fromLegacyCalendarConnection($this->connectionId)
            : $resolver->fromCalendarConnection($this->campaignId, $this->connectionId);
        $campaignId = $context->campaignId();

        try {
            $runner->run($context, function () use ($campaignId, $watch) {
                $connection = CalendarConnection::query()
                    ->where('campaign_id', $campaignId)
                    ->whereKey($this->connectionId)
                    ->firstOrFail();
                if ($connection->isReady()) {
                    $watch->renew($connection);
                }
            });
        } catch (Throwable $exception) {
            $failure = $failureClassifier->classify($exception);
            $connection = CalendarConnection::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($this->connectionId)
                ->first();

            if ($failure->requiresReconnect) {
                $connection?->markReconnectRequired();
            } else {
                $connection?->forceFill(['last_error' => $failure->safeMessage])->save();
            }

            if ($failure->retryable) {
                throw $failureClassifier->safeException($failure);
            }
        }
    }
}
