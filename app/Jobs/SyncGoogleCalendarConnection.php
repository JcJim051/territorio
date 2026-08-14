<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Models\CalendarSyncRun;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarSync;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class SyncGoogleCalendarConnection implements ShouldQueue
{
    use Queueable;

    public ?int $campaignId = null;

    public int $connectionId;

    public bool $forceFull = false;

    public ?int $syncRunId = null;

    public int $tries = 6;

    public int $timeout = 75;

    public array $backoff = [30, 60, 180, 600, 1800];

    public function __construct(
        int $campaignId,
        int $connectionId,
        bool $forceFull = false,
        ?int $syncRunId = null,
    ) {
        $this->campaignId = $campaignId;
        $this->connectionId = $connectionId;
        $this->forceFull = $forceFull;
        $this->syncRunId = $syncRunId;
        $this->afterCommit();
    }

    public function handle(
        GoogleCalendarSync $sync,
        GoogleCalendarFailureClassifier $failureClassifier,
        CampaignContextResolver $resolver,
        AuthorizedContextRunner $runner,
    ): void {
        $context = $this->campaignId === null
            ? $resolver->fromLegacyCalendarConnection($this->connectionId)
            : $resolver->fromCalendarConnection($this->campaignId, $this->connectionId);
        $campaignId = $context->campaignId();

        $runner->run($context, function () use ($campaignId, $failureClassifier, $sync) {
            $connection = CalendarConnection::query()
                ->where('campaign_id', $campaignId)
                ->whereKey($this->connectionId)
                ->firstOrFail();
            $run = $this->syncRun($campaignId);
            if (! $connection->isReady()) {
                $run?->finish(
                    'failed',
                    errorCode: 'connection_not_ready',
                    message: $connection->status === 'reconnect_required'
                        ? 'Debes volver a vincular la cuenta de Google.'
                        : 'La conexión de Google Calendar no está lista.',
                );

                return;
            }

            $lock = Cache::lock('google-calendar:sync:'.$campaignId.':'.$connection->id, 85);
            if (! $lock->get()) {
                $this->release(30);

                return;
            }

            $leaseOwner = (string) Str::uuid();

            try {
                if ($run && ! $run->startLease($leaseOwner, 300)) {
                    return;
                }

                $counts = $sync->sync(
                    $connection,
                    $this->forceFull,
                    fn () => $run?->heartbeat($leaseOwner, 300),
                );
                $run?->finish(
                    'succeeded',
                    counts: $counts,
                    message: 'Sincronización completada correctamente.',
                    leaseOwner: $leaseOwner,
                );
            } catch (Throwable $exception) {
                $failure = $failureClassifier->classify($exception);

                if ($failure->requiresReconnect) {
                    $connection->markReconnectRequired();
                } else {
                    $connection->forceFill(['last_error' => $failure->safeMessage])->save();
                }

                if ($failure->retryable) {
                    $run?->releaseForRetry($leaseOwner, $failure->code, $failure->safeMessage);

                    throw $failureClassifier->safeException($failure);
                }

                $run?->finish(
                    'failed',
                    errorCode: $failure->code,
                    message: $failure->safeMessage,
                    leaseOwner: $run?->lease_owner ? $leaseOwner : null,
                );
            } finally {
                $lock->release();
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->campaignId === null) {
            return;
        }

        $this->syncRun($this->campaignId)?->finish(
            'failed',
            errorCode: 'retries_exhausted',
            message: 'No fue posible completar la sincronización después de varios intentos.',
        );
    }

    private function syncRun(int $campaignId): ?CalendarSyncRun
    {
        if (! $this->syncRunId) {
            return null;
        }

        return CalendarSyncRun::query()
            ->where('campaign_id', $campaignId)
            ->where('calendar_connection_id', $this->connectionId)
            ->whereKey($this->syncRunId)
            ->first();
    }
}
