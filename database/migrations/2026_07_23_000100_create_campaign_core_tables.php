<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('candidate_name');
            $table->string('office');
            $table->string('territory');
            $table->date('starts_at')->nullable();
            $table->date('election_at')->nullable();
            $table->string('status')->default('active');
            $table->string('timezone')->default('America/Bogota');
            $table->json('enabled_modules')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('campaign_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['campaign_id', 'slug']);
        });

        Schema::create('campaign_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_role_id')->nullable()->constrained()->nullOnDelete();
            $table->json('territorial_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_memberships');
        Schema::dropIfExists('campaign_roles');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('organizations');
    }
};
