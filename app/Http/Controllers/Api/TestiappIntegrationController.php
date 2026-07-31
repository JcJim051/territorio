<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestiappIntegrationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'max:120'],
            'aggregate_type' => ['required', 'string', 'max:120'],
            'aggregate_id' => ['required', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        $created = DB::table('outbox_events')->insertOrIgnore([
            'event_id' => $data['event_id'],
            'campaign_id' => $data['campaign_id'] ?? null,
            'type' => 'testiapp.inbound.'.$data['type'],
            'aggregate_type' => $data['aggregate_type'],
            'aggregate_id' => $data['aggregate_id'],
            'payload' => json_encode($data['payload']),
            'occurred_at' => $data['occurred_at'],
            'published_at' => now(),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'accepted' => true,
            'duplicate' => ! (bool) $created,
            'receipt' => (string) Str::uuid(),
        ], $created ? 202 : 200);
    }
}
