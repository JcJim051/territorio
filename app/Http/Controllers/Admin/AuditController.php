<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\AuditRedactor;
use App\Support\CurrentCampaign;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $current->authorize('audit.view');
        [$query, $filters] = $this->filtered($request, $current);
        $events = $query->paginate(50)->withQueryString()->through(fn ($row) => $this->serialize($row));

        return Inertia::render('Admin/Audit/Index', [
            'events' => $events,
            'filters' => $filters,
            'users' => DB::table('campaign_memberships')
                ->join('users', 'users.id', '=', 'campaign_memberships.user_id')
                ->where('campaign_memberships.campaign_id', $current->campaign->id)
                ->distinct()->orderBy('users.name')->get(['users.id', 'users.name']),
            'canExport' => $current->membership->can('audit.export'),
        ]);
    }

    public function export(Request $request, CurrentCampaign $current): StreamedResponse|RedirectResponse
    {
        $current->authorize('audit.export');
        [$query] = $this->filtered($request, $current);
        if ((clone $query)->limit(50001)->count() > 50000) {
            return back()->with('error', 'La exportación supera 50.000 registros. Acota las fechas o los filtros.');
        }

        Audit::record('audit.exported', campaign: $current->campaign, newValues: ['filters' => $request->only(['from', 'to', 'user', 'event', 'module'])]);

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Fecha', 'Usuario', 'Evento', 'Módulo', 'Tipo', 'ID', 'Antes', 'Después']);
            $query->orderBy('audit_events.id')->chunk(1000, function ($rows) use ($output) {
                foreach ($rows as $row) {
                    $serialized = $this->serialize($row);
                    fputcsv($output, [
                        $serialized['createdAt'], $serialized['user'] ?: 'Sistema', $serialized['event'],
                        $serialized['module'], $serialized['auditableType'], $serialized['auditableId'],
                        json_encode($serialized['oldValues'], JSON_UNESCAPED_UNICODE),
                        json_encode($serialized['newValues'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
            fclose($output);
        }, 'auditoria-campana-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request, CurrentCampaign $current): array
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:80'],
        ]);
        $query = DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.user_id')
            ->where('audit_events.campaign_id', $current->campaign->id)
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('audit_events.created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('audit_events.created_at', '<=', $date))
            ->when($filters['user'] ?? null, fn (Builder $q, $user) => $q->where('audit_events.user_id', $user))
            ->when($filters['event'] ?? null, fn (Builder $q, $event) => $q->where('audit_events.event', 'like', '%'.$event.'%'))
            ->when($filters['module'] ?? null, fn (Builder $q, $module) => $q->where('audit_events.event', 'like', $module.'.%'))
            ->select('audit_events.*', 'users.name as user_name')
            ->orderByDesc('audit_events.id');

        return [$query, $filters];
    }

    private function serialize(object $row): array
    {
        $eventParts = explode('.', $row->event);
        return [
            'id' => $row->id,
            'createdAt' => $row->created_at,
            'user' => $row->user_name,
            'event' => $row->event,
            'module' => $eventParts[0] ?? 'system',
            'auditableType' => $row->auditable_type ? class_basename($row->auditable_type) : null,
            'auditableId' => $row->auditable_id,
            'oldValues' => AuditRedactor::clean(json_decode($row->old_values ?: '[]', true) ?: []),
            'newValues' => AuditRedactor::clean(json_decode($row->new_values ?: '[]', true) ?: []),
        ];
    }
}
