<?php

namespace App\Support;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(
        string $event,
        ?Model $auditable = null,
        array $newValues = [],
        array $oldValues = [],
        ?Campaign $campaign = null,
        ?Request $request = null,
    ): void {
        $request ??= request();

        DB::table('audit_events')->insert([
            'organization_id' => $campaign?->organization_id,
            'campaign_id' => $campaign?->id,
            'user_id' => $request->user()?->id,
            'event' => $event,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues ? json_encode(AuditRedactor::clean($oldValues)) : null,
            'new_values' => $newValues ? json_encode(AuditRedactor::clean($newValues)) : null,
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), config('app.key')) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
