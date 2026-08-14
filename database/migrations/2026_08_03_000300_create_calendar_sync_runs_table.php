<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger');
            $table->boolean('force_full')->default(false);
            $table->string('status')->default('queued');
            $table->string('active_key')->nullable()->unique();
            $table->json('counts')->nullable();
            $table->string('error_code')->nullable();
            $table->text('safe_message')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'created_at']);
            $table->index(['calendar_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_runs');
    }
};
