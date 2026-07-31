<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->date('election_at')->nullable();
            $table->string('status')->default('active');
            $table->string('legacy_id')->nullable();
            $table->timestamps();
        });

        Schema::create('divipol_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->nullable();
            $table->date('cutoff_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('territory_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('divipol_snapshot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('territory_units')->cascadeOnDelete();
            $table->string('type');
            $table->string('code');
            $table->string('name');
            $table->string('path')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'divipol_snapshot_id', 'type', 'code'], 'territory_units_unique');
            $table->index(['campaign_id', 'type']);
            $table->index('path');
        });

        Schema::create('voting_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('divipol_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dd', 2);
            $table->string('mm', 3);
            $table->string('zz', 2);
            $table->string('pp', 2);
            $table->string('unique_code')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('commune')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('census')->default(0);
            $table->unsignedInteger('tables_count')->default(0);
            $table->timestamps();

            $table->unique(['divipol_snapshot_id', 'dd', 'mm', 'zz', 'pp'], 'voting_places_divipol_unique');
            $table->index(['campaign_id', 'commune']);
        });

        Schema::create('voting_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voting_place_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('census')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['voting_place_id', 'number']);
        });

        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voting_place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voting_table_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('search_name')->index();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('document_number')->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->string('document_last_four', 4)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['campaign_id', 'document_hash']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('version');
            $table->string('text_hash', 64);
            $table->string('channel');
            $table->timestamp('accepted_at');
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->boolean('is_encrypted')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('public_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->json('abilities');
            $table->json('territorial_scope')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('public_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('invitee_person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->string('status')->default('opened');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('child_person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('referral_invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path')->nullable();
            $table->unsignedInteger('depth')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('change_reason')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'parent_person_id', 'ended_at']);
            $table->index(['campaign_id', 'child_person_id', 'ended_at']);
        });

        Schema::create('territorial_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('territory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->string('title')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territorial_roles');
        Schema::dropIfExists('referral_relationships');
        Schema::dropIfExists('referral_invitations');
        Schema::dropIfExists('public_tokens');
        Schema::dropIfExists('identity_documents');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('voting_tables');
        Schema::dropIfExists('voting_places');
        Schema::dropIfExists('territory_units');
        Schema::dropIfExists('divipol_snapshots');
        Schema::dropIfExists('elections');
    }
};
