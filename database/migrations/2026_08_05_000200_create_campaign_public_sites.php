<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_public_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('status')->default('draft');
            $table->json('draft_content')->nullable();
            $table->json('published_content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('public_site_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_public_site_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('image');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('external_url')->nullable();
            $table->string('alt_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('public_site_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_public_site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('handle')->nullable();
            $table->string('profile_url');
            $table->string('status')->default('not_configured');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_public_site_id', 'provider']);
        });

        Schema::create('public_site_social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_public_site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('source')->default('manual');
            $table->string('url');
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->string('media_url')->nullable();
            $table->date('published_on')->nullable();
            $table->boolean('featured')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->grantPublicSitePermission();
    }

    public function down(): void
    {
        Schema::dropIfExists('public_site_social_posts');
        Schema::dropIfExists('public_site_social_accounts');
        Schema::dropIfExists('public_site_media');
        Schema::dropIfExists('campaign_public_sites');
    }

    private function grantPublicSitePermission(): void
    {
        DB::table('campaign_roles')
            ->whereIn('slug', ['technical-administrator', 'manager'])
            ->get()
            ->each(function ($role) {
                $permissions = json_decode($role->permissions, true) ?: [];

                if (! in_array('public_site.manage', $permissions, true)) {
                    $permissions[] = 'public_site.manage';
                    DB::table('campaign_roles')->where('id', $role->id)->update([
                        'permissions' => json_encode(array_values($permissions)),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
