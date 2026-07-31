<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('provider')->default('google');
            $table->string('google_account_id')->nullable();
            $table->string('account_email')->nullable();
            $table->string('calendar_id')->nullable()->unique();
            $table->string('calendar_name')->nullable();
            $table->string('timezone')->default('America/Bogota');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('watch_channel_id')->nullable()->unique();
            $table->string('watch_resource_id')->nullable();
            $table->string('watch_token_hash', 64)->nullable();
            $table->timestamp('watch_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('external_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_event_id');
            $table->string('recurring_event_id')->nullable();
            $table->string('instance_key')->default('');
            $table->string('etag')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTimeTz('starts_at')->nullable();
            $table->dateTimeTz('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('is_busy')->default(true);
            $table->string('google_status')->default('confirmed');
            $table->string('review_status')->default('pending');
            $table->string('origin')->default('google');
            $table->string('html_link')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['calendar_connection_id', 'external_event_id', 'instance_key'], 'calendar_event_instance_unique');
            $table->index(['campaign_id', 'starts_at', 'ends_at', 'is_busy'], 'calendar_event_conflict_index');
        });

        Schema::create('calendar_change_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_calendar_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('change_type');
            $table->string('fingerprint', 64);
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['calendar_connection_id', 'fingerprint']);
            $table->index(['campaign_id', 'status', 'created_at']);
        });

        Schema::create('meeting_change_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('proposed_changes');
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'created_at']);
        });

        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->nullable()->after('campaign_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('sync_failures', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->nullable()->after('campaign_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sync_failures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calendar_connection_id');
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calendar_connection_id');
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('meeting_change_requests');
        Schema::dropIfExists('calendar_change_reviews');
        Schema::dropIfExists('external_calendar_events');
        Schema::dropIfExists('calendar_connections');
    }
};
