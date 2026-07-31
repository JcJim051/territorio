<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarConnection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleCalendarWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceId = $request->header('X-Goog-Resource-ID');
        $token = $request->header('X-Goog-Channel-Token');
        $connection = CalendarConnection::where('status', 'active')
            ->where('watch_channel_id', $channelId)
            ->where('watch_resource_id', $resourceId)
            ->first();
        abort_unless(
            $connection
            && $token
            && $connection->watch_token_hash
            && hash_equals($connection->watch_token_hash, hash('sha256', $token)),
            403,
        );

        SyncGoogleCalendarConnection::dispatch($connection->id);

        return response(status: 204);
    }
}
