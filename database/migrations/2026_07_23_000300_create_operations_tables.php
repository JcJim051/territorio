<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('leader_person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('territory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('objective')->nullable();
            $table->string('location')->nullable();
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->unsignedInteger('expected_attendees')->default(0);
            $table->unsignedInteger('actual_attendees')->default(0);
            $table->string('status')->default('requested');
            $table->text('approval_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('outcome')->nullable();
            $table->string('google_event_id')->nullable();
            $table->string('google_etag')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'starts_at', 'status']);
        });

        Schema::create('delegate_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'delegate_user_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('method')->default('list');
            $table->timestamp('checked_in_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'person_id']);
        });

        Schema::create('commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('kind');
            $table->string('unit')->default('unidad');
            $table->decimal('quantity', 14, 2)->default(0);
            $table->decimal('minimum_quantity', 14, 2)->default(0);
            $table->boolean('is_shared')->default(false);
            $table->string('status')->default('available');
            $table->timestamps();

            $table->index(['organization_id', 'campaign_id', 'kind']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('quantity', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['resource_id', 'starts_at', 'ends_at', 'status']);
        });

        Schema::create('meeting_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('shortage_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->decimal('required_quantity', 14, 2);
            $table->decimal('available_quantity', 14, 2);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTimeTz('due_at')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortage_tasks');
        Schema::dropIfExists('meeting_requirements');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('commitments');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('delegate_assignments');
        Schema::dropIfExists('meetings');
    }
};
