<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Meeting;
use App\Models\MeetingRequirement;
use App\Models\Reservation;
use App\Models\Resource;
use App\Notifications\CampaignActivityNotification;
use App\Support\CampaignNotifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingResourceService
{
    public function eligibleResources(Campaign $campaign): Collection
    {
        return Resource::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'available')
            ->where(fn ($query) => $query
                ->where('campaign_id', $campaign->id)
                ->orWhere(fn ($shared) => $shared->whereNull('campaign_id')->where('is_shared', true)))
            ->orderByRaw("case when kind = 'consumable' then 0 when kind = 'asset' then 1 when kind = 'equipment' then 2 else 3 end")
            ->orderBy('name')
            ->get();
    }

    public function syncRequirements(Meeting $meeting, array $requirements): void
    {
        $resourceIds = collect($requirements)->pluck('resource_id')->map(fn ($id) => (int) $id)->unique()->values();
        $eligible = $this->eligibleResources($meeting->campaign)->whereIn('id', $resourceIds)->keyBy('id');

        if ($eligible->count() !== $resourceIds->count()) {
            throw ValidationException::withMessages([
                'requirements' => 'Uno de los recursos no está disponible para esta campaña.',
            ]);
        }

        $meeting->requirements()->delete();

        foreach ($requirements as $requirement) {
            $resource = $eligible->get((int) $requirement['resource_id']);
            $meeting->requirements()->create([
                'campaign_id' => $meeting->campaign_id,
                'resource_id' => $resource->id,
                'name' => $resource->name,
                'quantity' => (float) $requirement['quantity'],
                'notes' => $requirement['notes'] ?? null,
                'status' => 'pending',
            ]);
        }

        $meeting->unsetRelation('requirements');
        $this->refreshShortages($meeting);
    }

    public function analyze(Meeting $meeting, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): Collection
    {
        $periodOverride = $startsAt !== null || $endsAt !== null;
        $startsAt ??= $meeting->starts_at;
        $endsAt ??= $meeting->ends_at;
        $requirements = $meeting->relationLoaded('requirements')
            ? $meeting->requirements
            : $meeting->requirements()->with('resource')->get();

        return $requirements->map(function (MeetingRequirement $requirement) use ($meeting, $startsAt, $endsAt, $periodOverride) {
            $resource = $requirement->resource;
            $required = (float) $requirement->quantity;
            $available = 0.0;
            $mode = $resource?->kind === 'consumable' ? 'consume' : 'reserve';

            if (! $periodOverride && $meeting->status === 'approved' && in_array($requirement->status, ['consumed', 'reserved'], true)) {
                return [
                    'id' => $requirement->id,
                    'resourceId' => $resource?->id,
                    'name' => $resource?->name ?? $requirement->name,
                    'kind' => $resource?->kind ?? 'unknown',
                    'unit' => $resource?->unit ?? 'unidad',
                    'mode' => $mode,
                    'required' => $required,
                    'available' => $required,
                    'missing' => 0.0,
                    'shortage' => false,
                    'status' => $requirement->status,
                    'notes' => $requirement->notes,
                ];
            }

            if ($resource?->status === 'available') {
                if ($mode === 'consume') {
                    $available = (float) $resource->quantity;
                } else {
                    $occupied = (float) Reservation::query()
                        ->where('resource_id', $resource->id)
                        ->where('status', 'confirmed')
                        ->where('meeting_id', '!=', $meeting->id)
                        ->where('starts_at', '<', $endsAt)
                        ->where('ends_at', '>', $startsAt)
                        ->sum('quantity');
                    $available = max(0, (float) $resource->quantity - $occupied);
                }
            }

            return [
                'id' => $requirement->id,
                'resourceId' => $resource?->id,
                'name' => $resource?->name ?? $requirement->name,
                'kind' => $resource?->kind ?? 'unknown',
                'unit' => $resource?->unit ?? 'unidad',
                'mode' => $mode,
                'required' => $required,
                'available' => $available,
                'missing' => max(0, $required - $available),
                'shortage' => $required > $available,
                'status' => $requirement->status,
                'notes' => $requirement->notes,
            ];
        })->values();
    }

    public function allocate(Meeting $meeting, int $userId): void
    {
        DB::transaction(function () use ($meeting, $userId) {
            $meeting->load('campaign');
            $requirements = $meeting->requirements()->with('resource')->orderBy('resource_id')->get();
            Resource::query()
                ->whereIn('id', $requirements->pluck('resource_id')->filter())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $meeting->setRelation('requirements', $meeting->requirements()->with('resource')->get());
            $analysis = $this->analyze($meeting);
            $shortages = $analysis->where('shortage', true);

            if ($shortages->isNotEmpty()) {
                $this->refreshShortages($meeting, $analysis);
                $first = $shortages->first();
                throw ValidationException::withMessages([
                    'resources' => "No se puede aprobar: faltan {$first['missing']} {$first['unit']} de {$first['name']}.",
                ]);
            }

            foreach ($meeting->requirements as $requirement) {
                $resource = $requirement->resource;

                if ($resource->kind === 'consumable') {
                    $alreadyConsumed = DB::table('stock_movements')
                        ->where('meeting_id', $meeting->id)
                        ->where('resource_id', $resource->id)
                        ->where('type', 'meeting_consumption')
                        ->exists();

                    if (! $alreadyConsumed) {
                        $resource->decrement('quantity', (float) $requirement->quantity);
                        DB::table('stock_movements')->insert([
                            'organization_id' => $meeting->campaign->organization_id,
                            'campaign_id' => $meeting->campaign_id,
                            'resource_id' => $resource->id,
                            'meeting_id' => $meeting->id,
                            'type' => 'meeting_consumption',
                            'quantity' => (float) $requirement->quantity,
                            'reference' => $meeting->public_id,
                            'notes' => "Consumo aprobado para {$meeting->title}",
                            'recorded_by' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $requirement->update(['status' => 'consumed']);
                    continue;
                }

                Reservation::query()->updateOrCreate(
                    ['meeting_id' => $meeting->id, 'resource_id' => $resource->id],
                    [
                        'campaign_id' => $meeting->campaign_id,
                        'quantity' => (float) $requirement->quantity,
                        'starts_at' => $meeting->starts_at,
                        'ends_at' => $meeting->ends_at,
                        'status' => 'confirmed',
                    ]
                );
                $requirement->update(['status' => 'reserved']);
            }

            DB::table('shortage_tasks')
                ->where('meeting_id', $meeting->id)
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'updated_at' => now()]);
        });
    }

    public function refreshShortages(Meeting $meeting, ?Collection $analysis = null): Collection
    {
        $analysis ??= $this->analyze($meeting);
        $shortages = $analysis->where('shortage', true);
        $shortageResourceIds = $shortages->pluck('resourceId')->filter()->all();

        DB::table('shortage_tasks')
            ->where('meeting_id', $meeting->id)
            ->where('status', 'open')
            ->when($shortageResourceIds !== [], fn ($query) => $query->whereNotIn('resource_id', $shortageResourceIds))
            ->update(['status' => 'resolved', 'updated_at' => now()]);

        foreach ($shortages as $shortage) {
            $existing = DB::table('shortage_tasks')
                ->where('meeting_id', $meeting->id)
                ->where('resource_id', $shortage['resourceId'])
                ->where('status', 'open')
                ->first();
            $values = [
                'required_quantity' => $shortage['required'],
                'available_quantity' => $shortage['available'],
                'due_at' => $meeting->starts_at,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('shortage_tasks')->where('id', $existing->id)->update($values);
            } else {
                DB::table('shortage_tasks')->insert([
                    'campaign_id' => $meeting->campaign_id,
                    'meeting_id' => $meeting->id,
                    'resource_id' => $shortage['resourceId'],
                    'status' => 'open',
                    'created_at' => now(),
                    ...$values,
                ]);
                app(CampaignNotifier::class)->notifyPermission(
                    $meeting->campaign_id,
                    'inventory.manage',
                    new CampaignActivityNotification(
                        $meeting->campaign_id,
                        'Faltante de inventario',
                        "Para {$meeting->title} faltan {$shortage['missing']} {$shortage['unit']} de {$shortage['name']}.",
                        '/inventory?alert=low',
                        'inventory',
                    ),
                );
            }
        }

        return $analysis;
    }

    public function assertAvailableForPeriod(Meeting $meeting, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $analysis = $this->analyze($meeting, $startsAt, $endsAt);
        $shortage = $analysis->where('mode', 'reserve')->firstWhere('shortage', true);

        if ($shortage) {
            throw ValidationException::withMessages([
                'resources' => "El nuevo horario no tiene disponibilidad de {$shortage['name']}: requiere {$shortage['required']} y hay {$shortage['available']}.",
            ]);
        }
    }

    public function rescheduleReservations(Meeting $meeting): void
    {
        Reservation::query()
            ->where('meeting_id', $meeting->id)
            ->where('status', 'confirmed')
            ->update([
                'starts_at' => $meeting->starts_at,
                'ends_at' => $meeting->ends_at,
                'updated_at' => now(),
            ]);
    }

    public function reject(Meeting $meeting): void
    {
        $meeting->requirements()->where('status', 'pending')->update(['status' => 'rejected']);
        DB::table('shortage_tasks')
            ->where('meeting_id', $meeting->id)
            ->where('status', 'open')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public function cancelAllocations(Meeting $meeting, int $userId): void
    {
        DB::transaction(function () use ($meeting, $userId) {
            $meeting->load('campaign');
            Reservation::query()
                ->where('meeting_id', $meeting->id)
                ->where('status', 'confirmed')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $consumptions = DB::table('stock_movements')
                ->where('meeting_id', $meeting->id)
                ->where('type', 'meeting_consumption')
                ->get();

            foreach ($consumptions as $consumption) {
                $alreadyReversed = DB::table('stock_movements')
                    ->where('meeting_id', $meeting->id)
                    ->where('resource_id', $consumption->resource_id)
                    ->where('type', 'meeting_consumption_reversal')
                    ->exists();

                if ($alreadyReversed) {
                    continue;
                }

                Resource::whereKey($consumption->resource_id)->lockForUpdate()->increment('quantity', (float) $consumption->quantity);
                DB::table('stock_movements')->insert([
                    'organization_id' => $meeting->campaign->organization_id,
                    'campaign_id' => $meeting->campaign_id,
                    'resource_id' => $consumption->resource_id,
                    'meeting_id' => $meeting->id,
                    'type' => 'meeting_consumption_reversal',
                    'quantity' => (float) $consumption->quantity,
                    'reference' => $meeting->public_id,
                    'notes' => "Reintegro por cancelación de {$meeting->title}",
                    'recorded_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $meeting->requirements()->update(['status' => 'cancelled']);
        });
    }
}
