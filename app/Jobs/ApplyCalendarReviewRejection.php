<?php

namespace App\Jobs;

use App\Models\CalendarChangeReview;
use App\Services\GoogleCalendarClientFactory;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarPublisher;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use App\Support\Tenancy\ExecutionContextStore;
use Google\Service\Calendar\Event;
use Google\Service\Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyCalendarReviewRejection implements ShouldQueue
{
    use Queueable;

    public ?int $campaignId = null;

    public int $reviewId;

    public int $tries = 8;

    public int $timeout = 75;

    public array $backoff = [30, 60, 180, 600, 1800];

    public function __construct(
        int $campaignId,
        int $reviewId,
    ) {
        $this->campaignId = $campaignId;
        $this->reviewId = $reviewId;
        $this->afterCommit();
    }

    public function handle(
        GoogleCalendarClientFactory $factory,
        GoogleCalendarFailureClassifier $failureClassifier,
        GoogleCalendarPublisher $publisher,
        CampaignContextResolver $resolver,
        AuthorizedContextRunner $runner,
        ExecutionContextStore $contextStore,
    ): void {
        $context = $this->campaignId === null
            ? $resolver->fromLegacyCalendarReview($this->reviewId)
            : $resolver->fromCalendarReview($this->campaignId, $this->reviewId);
        $campaignId = $context->campaignId();

        try {
            $runner->run($context, function () use ($campaignId, $contextStore, $factory, $publisher) {
                $review = CalendarChangeReview::query()
                    ->with(['connection', 'event', 'meeting'])
                    ->where('campaign_id', $campaignId)
                    ->whereKey($this->reviewId)
                    ->firstOrFail();
                if ($review->status !== 'rejected' || ! $review->connection?->isReady()) {
                    return;
                }

                $contextStore->assertCampaign((int) $review->connection->campaign_id);
                $event = $review->event;
                if (! $event) {
                    return;
                }
                $contextStore->assertCampaign((int) $event->campaign_id);

                if ($review->meeting) {
                    $contextStore->assertCampaign((int) $review->meeting->campaign_id);
                    $publisher->upsert($review->meeting);
                    $event->forceFill(['review_status' => 'approved'])->save();

                    return;
                }

                $service = $factory->service($review->connection);
                if ($review->change_type === 'created') {
                    try {
                        $service->events->delete($review->connection->calendar_id, $event->external_event_id);
                    } catch (Exception $exception) {
                        if (! in_array($exception->getCode(), [404, 410], true)) {
                            throw $exception;
                        }
                    }
                    $event->delete();

                    return;
                }

                $before = $review->before_payload ?? [];
                $googleEvent = new Event([
                    'summary' => $before['title'] ?? '(Sin título)',
                    'description' => $before['description'] ?? null,
                    'location' => $before['location'] ?? null,
                    'transparency' => ($before['is_busy'] ?? true) ? 'opaque' : 'transparent',
                    'start' => $this->datePayload($before, 'starts_at'),
                    'end' => $this->datePayload($before, 'ends_at'),
                ]);

                if ($review->change_type === 'deleted') {
                    $saved = $service->events->insert($review->connection->calendar_id, $googleEvent);
                    $event->external_event_id = $saved->getId();
                    $event->etag = $saved->getEtag();
                    $event->html_link = $saved->getHtmlLink();
                } else {
                    $saved = $service->events->update($review->connection->calendar_id, $event->external_event_id, $googleEvent);
                    $event->etag = $saved->getEtag();
                }
                $event->forceFill([
                    'title' => $before['title'] ?? $event->title,
                    'location' => $before['location'] ?? $event->location,
                    'starts_at' => $before['starts_at'] ?? $event->starts_at,
                    'ends_at' => $before['ends_at'] ?? $event->ends_at,
                    'all_day' => $before['all_day'] ?? false,
                    'is_busy' => $before['is_busy'] ?? true,
                    'google_status' => 'confirmed',
                    'review_status' => 'approved',
                ])->save();
            });
        } catch (Throwable $exception) {
            $failure = $failureClassifier->classify($exception);
            $review = CalendarChangeReview::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($this->reviewId)
                ->first();

            if ($failure->requiresReconnect) {
                $review?->connection?->markReconnectRequired();
            }
            $review?->forceFill(['failure_reason' => $failure->safeMessage])->save();

            if ($failure->retryable) {
                throw $failureClassifier->safeException($failure);
            }
        }
    }

    private function datePayload(array $payload, string $key): array
    {
        if ($payload['all_day'] ?? false) {
            return ['date' => substr((string) ($payload[$key] ?? ''), 0, 10)];
        }

        return [
            'dateTime' => $payload[$key] ?? now()->toRfc3339String(),
            'timeZone' => 'America/Bogota',
        ];
    }
}
