<?php

namespace App\Jobs;

use App\Enums\CalendarPublicationResult;
use App\Exceptions\CalendarPublicationDeferred;
use App\Models\CalendarConnection;
use App\Models\Meeting;
use App\Models\OutboxEvent;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarPublisher;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use App\Support\Tenancy\ExecutionContextStore;
use App\Support\Tenancy\UnauthorizedExecutionContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessCalendarOutbox implements ShouldQueue
{
    use Queueable;

    public ?int $campaignId = null;

    public int $tries = 8;

    public int $timeout = 75;

    public array $backoff = [30, 60, 120, 300, 600, 1800];

    public function __construct(?int $campaignId = null)
    {
        $this->campaignId = $campaignId;
        $this->afterCommit();
    }

    public function handle(
        GoogleCalendarPublisher $publisher,
        GoogleCalendarFailureClassifier $failureClassifier,
        CampaignContextResolver $resolver,
        AuthorizedContextRunner $runner,
        ExecutionContextStore $contextStore,
    ): void {
        if ($this->campaignId === null) {
            OutboxEvent::query()
                ->whereNotNull('campaign_id')
                ->whereNull('published_at')
                ->whereIn('type', ['calendar.meeting.upsert', 'calendar.meeting.delete'])
                ->distinct()
                ->pluck('campaign_id')
                ->each(fn ($campaignId) => self::dispatch((int) $campaignId));

            return;
        }

        $lock = Cache::lock('google-calendar:outbox:'.$this->campaignId, 85);
        if (! $lock->get()) {
            return;
        }

        try {
            OutboxEvent::query()
                ->where('campaign_id', $this->campaignId)
                ->whereNull('published_at')
                ->whereIn('type', ['calendar.meeting.upsert', 'calendar.meeting.delete'])
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'campaign_id'])
                ->each(function (OutboxEvent $reference) use ($contextStore, $failureClassifier, $publisher, $resolver, $runner) {
                    try {
                        $campaignId = (int) $reference->campaign_id;
                        $context = $resolver->fromOutboxEvent($campaignId, (int) $reference->id);
                        $runner->runOutbox($context, function () use ($campaignId, $contextStore, $publisher, $reference) {
                            $event = OutboxEvent::query()
                                ->where('campaign_id', $campaignId)
                                ->whereKey($reference->id)
                                ->firstOrFail();
                            $contextStore->assertCampaign((int) $event->campaign_id);
                            $meetingId = (int) ($event->payload['meeting_id'] ?? 0);
                            if (
                                (int) ($event->payload['campaign_id'] ?? 0) !== $campaignId
                                || (string) $meetingId !== (string) $event->aggregate_id
                                || $event->aggregate_type !== Meeting::class
                            ) {
                                throw new UnauthorizedExecutionContext('El evento outbox cambió después de autorizarse.');
                            }

                            if ($event->type === 'calendar.meeting.delete') {
                                $result = $publisher->deleteByIdentifiers($campaignId, $meetingId);
                            } else {
                                $meeting = Meeting::query()
                                    ->where('campaign_id', $campaignId)
                                    ->whereKey($meetingId)
                                    ->first();
                                if (! $meeting) {
                                    $result = CalendarPublicationResult::TerminalNoop;
                                } elseif ($meeting->public_id !== ($event->payload['meeting_public_id'] ?? '')) {
                                    throw new UnauthorizedExecutionContext('La reunión del outbox no coincide con su referencia durable.');
                                } else {
                                    $result = $publisher->upsert($meeting);
                                }
                            }

                            if (! in_array($result, [CalendarPublicationResult::Confirmed, CalendarPublicationResult::TerminalNoop], true)) {
                                throw new CalendarPublicationDeferred;
                            }

                            $event->forceFill(['published_at' => now(), 'last_error' => null])->save();
                        });
                    } catch (CalendarPublicationDeferred) {
                        OutboxEvent::query()
                            ->where('campaign_id', $reference->campaign_id)
                            ->whereKey($reference->id)
                            ->update(['last_error' => 'La publicación espera una conexión activa de Google Calendar.']);
                    } catch (\Throwable $exception) {
                        $event = OutboxEvent::query()
                            ->where('campaign_id', $reference->campaign_id)
                            ->whereKey($reference->id)
                            ->first();
                        if (! $event) {
                            return;
                        }

                        if ($exception instanceof UnauthorizedExecutionContext) {
                            $event->increment('attempts');
                            $event->forceFill(['last_error' => 'El evento no superó la validación de aislamiento.'])->save();

                            throw $exception;
                        }

                        $failure = $failureClassifier->classify($exception);
                        $event->increment('attempts');
                        $event->forceFill(['last_error' => $failure->safeMessage])->save();

                        if ($failure->requiresReconnect) {
                            CalendarConnection::query()
                                ->where('campaign_id', $reference->campaign_id)
                                ->first()?->markReconnectRequired();

                            return false;
                        }

                        if ($failure->retryable) {
                            throw $failureClassifier->safeException($failure);
                        }
                    }
                });
        } finally {
            $lock->release();
        }
    }
}
