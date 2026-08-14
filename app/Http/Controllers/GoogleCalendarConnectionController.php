<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCalendarOutbox;
use App\Models\CalendarConnection;
use App\Models\CalendarSyncRun;
use App\Models\CampaignServiceCredential;
use App\Models\Meeting;
use App\Services\CalendarOutbox;
use App\Services\CalendarSyncDispatcher;
use App\Services\GoogleCalendarClientFactory;
use App\Services\GoogleCalendarFailureClassifier;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Google\Service\Calendar;
use Google\Service\Oauth2;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GoogleCalendarConnectionController extends Controller
{
    public function index(
        Request $request,
        CurrentCampaign $current,
        GoogleCalendarClientFactory $factory,
        GoogleCalendarFailureClassifier $failureClassifier,
    ): Response {
        abort_unless(
            $current->membership->can('calendar.connections.manage') || $current->membership->can('calendar.sync.view'),
            403,
        );
        $connection = CalendarConnection::where('campaign_id', $current->campaign->id)->first();
        $serviceConfiguration = CampaignServiceCredential::where('campaign_id', $current->campaign->id)
            ->where('provider', 'google_calendar')
            ->first();
        $calendars = [];
        if ($connection?->access_token && ($connection->status !== 'active' || $request->boolean('refresh'))) {
            try {
                $calendars = $this->availableCalendars($connection, $factory);
            } catch (\Throwable $exception) {
                $failure = $failureClassifier->classify($exception);
                if ($failure->requiresReconnect) {
                    $connection->markReconnectRequired();
                } else {
                    $connection->update(['last_error' => $failure->safeMessage]);
                }
            }
        }

        return Inertia::render('Calendar/Settings', [
            'connection' => $connection ? [
                'status' => $connection->status,
                'email' => $connection->account_email,
                'calendarId' => $connection->calendar_id,
                'calendarName' => $connection->calendar_name,
                'timezone' => $connection->timezone,
                'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
                'lastError' => $connection->last_error,
                'watchEnabled' => filled($connection->watch_channel_id),
                'watchExpiresAt' => $connection->watch_expires_at?->toIso8601String(),
            ] : null,
            'calendars' => $calendars,
            'configured' => (bool) $serviceConfiguration
                && filled($serviceConfiguration->credentials['client_id'] ?? null)
                && filled($serviceConfiguration->credentials['client_secret'] ?? null)
                && filled($serviceConfiguration->settings['redirect_uri'] ?? null),
            'serviceConfiguration' => $current->membership->can('integrations.manage') ? [
                'clientId' => $serviceConfiguration?->credentials['client_id'],
                'secretConfigured' => filled($serviceConfiguration?->credentials['client_secret']),
                'redirectUri' => $serviceConfiguration?->settings['redirect_uri'] ?? route('calendar.oauth.callback'),
                'webhookUrl' => $serviceConfiguration?->settings['webhook_url'] ?? '',
                'updatedAt' => $serviceConfiguration?->updated_at?->toIso8601String(),
            ] : null,
            'permissions' => [
                'manage' => $current->membership->can('calendar.connections.manage'),
                'viewSync' => $current->membership->can('calendar.sync.view'),
                'configureServices' => $current->membership->can('integrations.manage'),
            ],
            'syncRuns' => CalendarSyncRun::query()
                ->where('campaign_id', $current->campaign->id)
                ->when($connection, fn ($query) => $query->where('calendar_connection_id', $connection->id))
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (CalendarSyncRun $run) => $this->syncRunPayload($run)),
        ]);
    }

    public function configure(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('integrations.manage');
        $existing = CampaignServiceCredential::where('campaign_id', $current->campaign->id)
            ->where('provider', 'google_calendar')
            ->first();
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:1024'],
            'client_secret' => [
                Rule::requiredIf(! filled($existing?->credentials['client_secret'] ?? null)),
                'nullable',
                'string',
                'max:2048',
            ],
            'redirect_uri' => ['required', 'url:http,https', 'max:2048'],
            'webhook_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $oldCredentials = $existing?->credentials ?? [];
        $newCredentials = [
            'client_id' => $data['client_id'],
            'client_secret' => filled($data['client_secret'] ?? null)
                ? $data['client_secret']
                : ($oldCredentials['client_secret'] ?? null),
        ];
        $credentialsChanged = ($oldCredentials['client_id'] ?? null) !== $newCredentials['client_id']
            || (filled($data['client_secret'] ?? null)
                && ($oldCredentials['client_secret'] ?? null) !== $data['client_secret']);

        DB::transaction(function () use ($current, $request, $existing, $newCredentials, $data, $credentialsChanged) {
            $configuration = CampaignServiceCredential::updateOrCreate(
                ['campaign_id' => $current->campaign->id, 'provider' => 'google_calendar'],
                [
                    'label' => 'Google Calendar',
                    'credentials' => $newCredentials,
                    'settings' => [
                        'redirect_uri' => $data['redirect_uri'],
                        'webhook_url' => $data['webhook_url'] ?? null,
                    ],
                    'configured_by' => $request->user()->id,
                ],
            );

            if ($credentialsChanged) {
                CalendarConnection::where('campaign_id', $current->campaign->id)->update([
                    'status' => 'reconnect_required',
                    'access_token' => null,
                    'refresh_token' => null,
                    'watch_channel_id' => null,
                    'watch_resource_id' => null,
                    'watch_token_hash' => null,
                    'watch_expires_at' => null,
                    'last_error' => null,
                ]);
            }

            Audit::record('calendar.service_configured', $configuration, [
                'client_id_suffix' => Str::mask($newCredentials['client_id'], '*', 0, max(0, strlen($newCredentials['client_id']) - 8)),
                'redirect_uri' => $data['redirect_uri'],
                'webhook_configured' => filled($data['webhook_url'] ?? null),
                'connection_requires_reconnect' => $credentialsChanged && (bool) $existing,
            ], campaign: $current->campaign);
        });

        return back()->with(
            'success',
            $credentialsChanged && CalendarConnection::where('campaign_id', $current->campaign->id)->exists()
                ? 'Configuración guardada. Debes volver a vincular la cuenta porque cambiaron las credenciales OAuth.'
                : 'Configuración de Google guardada para '.$current->campaign->candidate_name.'.',
        );
    }

    public function redirect(Request $request, CurrentCampaign $current, GoogleCalendarClientFactory $factory): RedirectResponse
    {
        $current->authorize('calendar.connections.manage');
        $factory->configurationForCampaign($current->campaign->id);

        $state = Str::random(64);
        $request->session()->put('google_calendar_oauth', [
            'state_hash' => hash('sha256', $state),
            'campaign_id' => $current->campaign->id,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);
        $client = $factory->client(campaign: $current->campaign);
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(
        Request $request,
        CurrentCampaign $current,
        GoogleCalendarClientFactory $factory,
        GoogleCalendarFailureClassifier $failureClassifier,
    ): RedirectResponse {
        $current->authorize('calendar.connections.manage');
        $oauth = $request->session()->pull('google_calendar_oauth');
        abort_unless(
            is_array($oauth)
            && hash_equals($oauth['state_hash'] ?? '', hash('sha256', $request->string('state')->toString()))
            && ($oauth['campaign_id'] ?? null) === $current->campaign->id
            && ($oauth['user_id'] ?? null) === $request->user()->id
            && ($oauth['expires_at'] ?? 0) >= now()->getTimestamp(),
            403,
            'El intento de vinculación venció o no es válido.',
        );

        if ($request->filled('error')) {
            return redirect()->route('calendar.settings')->with('error', 'Google no autorizó la vinculación.');
        }
        $request->validate(['code' => ['required', 'string']]);
        $client = $factory->client(campaign: $current->campaign);
        $token = $client->fetchAccessTokenWithAuthCode($request->string('code')->toString());
        if (isset($token['error'])) {
            $failure = $failureClassifier->classifyTokenResponse($token);
            throw ValidationException::withMessages(['google' => $failure->safeMessage]);
        }
        $client->setAccessToken($token);
        $profile = (new Oauth2($client))->userinfo->get();
        $existing = CalendarConnection::where('campaign_id', $current->campaign->id)->first();
        $refreshToken = $token['refresh_token'] ?? $existing?->refresh_token;
        abort_unless($refreshToken, 422, 'Google no entregó acceso offline. Revoca el acceso anterior e inténtalo nuevamente.');

        $connection = CalendarConnection::updateOrCreate(
            ['campaign_id' => $current->campaign->id],
            [
                'provider' => 'google',
                'google_account_id' => $profile->getId(),
                'account_email' => $profile->getEmail(),
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600) - 60)),
                'scopes' => explode(' ', $token['scope'] ?? ''),
                'status' => 'pending_selection',
                'connected_by' => $request->user()->id,
                'disconnected_at' => null,
                'last_error' => null,
            ],
        );
        Audit::record('calendar.account_connected', $connection, ['email' => $connection->account_email], campaign: $current->campaign);

        return redirect()->route('calendar.settings', ['refresh' => 1])
            ->with('success', 'Cuenta vinculada. Selecciona el calendario del candidato.');
    }

    public function select(
        Request $request,
        CurrentCampaign $current,
        GoogleCalendarClientFactory $factory,
        CalendarOutbox $outbox,
        CalendarSyncDispatcher $syncDispatcher,
    ): RedirectResponse {
        $current->authorize('calendar.connections.manage');
        $data = $request->validate(['calendar_id' => ['required', 'string', 'max:1024']]);
        $connection = CalendarConnection::where('campaign_id', $current->campaign->id)->firstOrFail();
        $calendar = collect($this->availableCalendars($connection, $factory))
            ->firstWhere('id', $data['calendar_id']);
        throw_unless($calendar, ValidationException::withMessages(['calendar_id' => 'El calendario no existe o no permite escritura.']));
        $used = CalendarConnection::where('calendar_id', $data['calendar_id'])
            ->where('campaign_id', '!=', $current->campaign->id)
            ->exists();
        throw_if($used, ValidationException::withMessages(['calendar_id' => 'Este calendario ya pertenece a otra campaña.']));

        DB::transaction(function () use ($connection, $calendar, $current, $outbox) {
            $connection->update([
                'calendar_id' => $calendar['id'],
                'calendar_name' => $calendar['name'],
                'timezone' => $calendar['timezone'] ?: 'America/Bogota',
                'status' => 'active',
                'last_error' => null,
            ]);
            Meeting::where('campaign_id', $current->campaign->id)
                ->where('status', 'approved')
                ->get()
                ->each(fn (Meeting $meeting) => $outbox->meetingUpsert($meeting));
        });
        $syncDispatcher->dispatch($connection->fresh(), 'calendar_selected', $request->user()->id, true);
        ProcessCalendarOutbox::dispatch((int) $current->campaign->id);
        Audit::record('calendar.selected', $connection, ['calendar' => $calendar['name']], campaign: $current->campaign);

        return back()->with('success', 'Calendario activado. La sincronización inicial está en curso.');
    }

    public function sync(Request $request, CurrentCampaign $current, CalendarSyncDispatcher $syncDispatcher): RedirectResponse
    {
        $current->authorize('calendar.connections.manage');
        $connection = CalendarConnection::where('campaign_id', $current->campaign->id)->where('status', 'active')->firstOrFail();
        $run = $syncDispatcher->dispatch($connection, 'manual', $request->user()->id);

        return back()->with(
            'success',
            $run->wasRecentlyCreated
                ? 'Sincronización encolada. Verás aquí su resultado.'
                : 'Ya existe una sincronización en curso para este calendario.',
        );
    }

    public function disconnect(CurrentCampaign $current, GoogleCalendarClientFactory $factory): RedirectResponse
    {
        $current->authorize('calendar.connections.manage');
        $connection = CalendarConnection::where('campaign_id', $current->campaign->id)->firstOrFail();
        try {
            if ($connection->access_token) {
                $factory->client($connection)->revokeToken();
            }
        } catch (\Throwable) {
            // Local revocation remains authoritative if Google is temporarily unavailable.
        }
        $connection->update([
            'status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'watch_channel_id' => null,
            'watch_resource_id' => null,
            'watch_token_hash' => null,
            'watch_expires_at' => null,
            'disconnected_at' => now(),
        ]);
        Audit::record('calendar.disconnected', $connection, ['disconnected' => true], campaign: $current->campaign);

        return back()->with('success', 'El calendario fue desconectado. Los eventos existentes en Google se conservaron.');
    }

    private function availableCalendars(CalendarConnection $connection, GoogleCalendarClientFactory $factory): array
    {
        $service = new Calendar($factory->client($connection));
        $items = [];
        $pageToken = null;
        do {
            $result = $service->calendarList->listCalendarList(['pageToken' => $pageToken]);
            foreach ($result->getItems() as $calendar) {
                if (! in_array($calendar->getAccessRole(), ['owner', 'writer'], true)) {
                    continue;
                }
                $items[] = [
                    'id' => $calendar->getId(),
                    'name' => $calendar->getSummary(),
                    'timezone' => $calendar->getTimeZone(),
                    'primary' => (bool) $calendar->getPrimary(),
                    'accessRole' => $calendar->getAccessRole(),
                ];
            }
            $pageToken = $result->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    private function syncRunPayload(CalendarSyncRun $run): array
    {
        return [
            'id' => $run->public_id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'counts' => $run->counts,
            'errorCode' => $run->error_code,
            'message' => $run->safe_message,
            'queuedAt' => $run->queued_at?->toIso8601String(),
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
        ];
    }
}
