<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CalendarSyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'force_full' => 'boolean',
            'counts' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CalendarSyncRun $run) {
            if ($run->calendar_connection_id && $run->campaign_id) {
                $matches = CalendarConnection::query()
                    ->where('campaign_id', $run->campaign_id)
                    ->whereKey($run->calendar_connection_id)
                    ->exists();

                if (! $matches) {
                    throw new LogicException('La ejecución no pertenece a la campaña de la conexión.');
                }
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function startLease(string $owner, int $seconds): bool
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->where('status', 'queued')
            ->whereNotNull('active_key')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($seconds),
                'heartbeat_at' => now(),
                'safe_message' => 'Consultando cambios en Google Calendar.',
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $updated === 1;
    }

    public function heartbeat(string $owner, int $seconds): bool
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->where('status', 'running')
            ->where('lease_owner', $owner)
            ->update([
                'lease_expires_at' => now()->addSeconds($seconds),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 1) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function releaseForRetry(string $owner, string $errorCode, string $message): bool
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->where('status', 'running')
            ->where('lease_owner', $owner)
            ->update([
                'status' => 'queued',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
                'error_code' => $errorCode,
                'safe_message' => $message,
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $updated === 1;
    }

    public function finish(
        string $status,
        ?array $counts = null,
        ?string $errorCode = null,
        ?string $message = null,
        ?string $leaseOwner = null,
    ): bool {
        if (! in_array($status, ['succeeded', 'failed'], true)) {
            throw new LogicException('Una ejecución solo puede finalizar como exitosa o fallida.');
        }

        $updated = self::query()
            ->whereKey($this->id)
            ->whereIn('status', ['queued', 'running'])
            ->when($leaseOwner, fn ($query) => $query->where('lease_owner', $leaseOwner))
            ->update([
                'status' => $status,
                'active_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
                'counts' => $counts,
                'error_code' => $errorCode,
                'safe_message' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $updated === 1;
    }
}
