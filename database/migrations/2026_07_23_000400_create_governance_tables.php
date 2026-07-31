<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'event', 'created_at']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('channel');
            $table->string('recipient');
            $table->string('template');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('system');
            $table->string('entity_type');
            $table->string('local_id');
            $table->string('external_id');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['system', 'entity_type', 'external_id']);
            $table->index(['system', 'entity_type', 'local_id']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('system');
            $table->string('stream');
            $table->string('cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['system', 'stream']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_failures', function (Blueprint $table) {
            $table->id();
            $table->string('system');
            $table->string('stream');
            $table->string('external_id')->nullable();
            $table->json('payload')->nullable();
            $table->text('error');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_failures');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('sync_cursors');
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('audit_events');
    }
};
