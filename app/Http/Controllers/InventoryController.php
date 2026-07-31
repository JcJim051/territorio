<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $current->authorize('inventory.view');
        $campaign = $current->campaign;

        $allResources = Resource::query()
            ->where('organization_id', $campaign->organization_id)
            ->where(fn ($query) => $query
                ->where('campaign_id', $campaign->id)
                ->orWhere(fn ($shared) => $shared->whereNull('campaign_id')->where('is_shared', true)))
            ->orderBy('name')
            ->get();

        $resources = $allResources
            ->when(
                $request->string('alert')->toString() === 'low',
                fn ($items) => $items->filter(fn (Resource $resource) => $resource->quantity <= $resource->minimum_quantity)
            )
            ->map(function (Resource $resource) {
                $reservations = DB::table('reservations')
                    ->join('meetings', 'reservations.meeting_id', '=', 'meetings.id')
                    ->where('reservations.resource_id', $resource->id)
                    ->where('reservations.status', 'confirmed')
                    ->where('reservations.ends_at', '>', now())
                    ->orderBy('reservations.starts_at');
                $nextReservation = (clone $reservations)->first([
                    'meetings.title',
                    'reservations.quantity',
                    'reservations.starts_at',
                    'reservations.ends_at',
                ]);

                return [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'sku' => $resource->sku,
                    'kind' => $resource->kind,
                    'unit' => $resource->unit,
                    'quantity' => (float) $resource->quantity,
                    'minimumQuantity' => (float) $resource->minimum_quantity,
                    'isShared' => (bool) $resource->is_shared,
                    'status' => $resource->status,
                    'movementsCount' => DB::table('stock_movements')->where('resource_id', $resource->id)->count(),
                    'occupiedNow' => (float) DB::table('reservations')
                        ->where('resource_id', $resource->id)
                        ->where('status', 'confirmed')
                        ->where('starts_at', '<=', now())
                        ->where('ends_at', '>', now())
                        ->sum('quantity'),
                    'upcomingReservations' => (clone $reservations)->count(),
                    'nextReservation' => $nextReservation ? [
                        'title' => $nextReservation->title,
                        'quantity' => (float) $nextReservation->quantity,
                        'startsAt' => $nextReservation->starts_at,
                        'endsAt' => $nextReservation->ends_at,
                    ] : null,
                ];
            });

        return Inertia::render('Inventory/Index', [
            'resources' => $resources,
            'summary' => [
                'total' => $allResources->count(),
                'alerts' => $allResources->filter(fn (Resource $resource) => $resource->quantity <= $resource->minimum_quantity)->count(),
                'assets' => $allResources->where('kind', 'asset')->count(),
                'consumables' => $allResources->where('kind', 'consumable')->count(),
            ],
            'filters' => ['alert' => $request->string('alert')->toString()],
        ]);
    }

    public function store(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('inventory.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', 'in:consumable,asset,equipment,service'],
            'unit' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'minimum_quantity' => ['required', 'numeric', 'min:0'],
            'is_shared' => ['boolean'],
        ]);

        $resource = Resource::create([
            ...$data,
            'organization_id' => $current->campaign->organization_id,
            'campaign_id' => $request->boolean('is_shared') ? null : $current->campaign->id,
            'is_shared' => $request->boolean('is_shared'),
        ]);
        Audit::record('inventory.resource_created', $resource, $data, campaign: $current->campaign);

        return back()->with('success', 'El recurso fue creado en el inventario.');
    }

    public function update(Request $request, int $resourceId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('inventory.manage');
        $resource = $this->findResource($current, $resourceId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', 'in:consumable,asset,equipment,service'],
            'unit' => ['required', 'string', 'max:40'],
            'minimum_quantity' => ['required', 'numeric', 'min:0'],
            'is_shared' => ['boolean'],
            'status' => ['required', 'in:available,maintenance,inactive,archived'],
        ]);
        $old = $resource->only(['name', 'sku', 'kind', 'unit', 'minimum_quantity', 'is_shared', 'status']);
        $resource->update([
            ...$data,
            'campaign_id' => $request->boolean('is_shared') ? null : $current->campaign->id,
            'is_shared' => $request->boolean('is_shared'),
        ]);
        Audit::record('inventory.resource_updated', $resource, $data, $old, $current->campaign);

        return back()->with('success', 'El recurso fue actualizado.');
    }

    public function adjust(Request $request, int $resourceId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('inventory.manage');
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $resource = $this->findResource($current, $resourceId);

        DB::transaction(function () use ($resource, $data, $request, $current) {
            $lockedResource = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $newQuantity = (float) $lockedResource->quantity + (float) $data['quantity'];
            if ($newQuantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'El movimiento dejaría existencias negativas.']);
            }
            $oldQuantity = (float) $lockedResource->quantity;
            $lockedResource->update(['quantity' => $newQuantity]);
            DB::table('stock_movements')->insert([
                'organization_id' => $current->campaign->organization_id,
                'campaign_id' => $current->campaign->id,
                'resource_id' => $lockedResource->id,
                'type' => (float) $data['quantity'] > 0 ? 'entry' : 'adjustment_out',
                'quantity' => abs((float) $data['quantity']),
                'notes' => $data['notes'],
                'recorded_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record('inventory.adjusted', $lockedResource, ['quantity' => $newQuantity], ['quantity' => $oldQuantity], $current->campaign);
        });

        return back()->with('success', 'Existencias actualizadas y movimiento auditado.');
    }

    public function destroy(int $resourceId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('inventory.delete');
        $resource = $this->findResource($current, $resourceId);
        $hasHistory = DB::table('stock_movements')->where('resource_id', $resource->id)->exists()
            || DB::table('reservations')->where('resource_id', $resource->id)->exists();
        if ($hasHistory) {
            throw ValidationException::withMessages([
                'resource' => 'El recurso tiene movimientos o reservas. Archívalo para conservar la trazabilidad.',
            ]);
        }
        Audit::record('inventory.resource_deleted', $resource, ['deleted' => true], campaign: $current->campaign);
        $resource->delete();

        return back()->with('success', 'El recurso fue eliminado.');
    }

    private function findResource(CurrentCampaign $current, int $resourceId): Resource
    {
        return Resource::query()
            ->where('organization_id', $current->campaign->organization_id)
            ->whereKey($resourceId)
            ->where(fn ($query) => $query
                ->where('campaign_id', $current->campaign->id)
                ->orWhere(fn ($shared) => $shared->whereNull('campaign_id')->where('is_shared', true)))
            ->firstOrFail();
    }
}
