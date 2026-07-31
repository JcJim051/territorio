<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->boolean('is_referral_node')->default(false)->after('status');
            $table->foreignId('promoted_by')->nullable()->after('is_referral_node')->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable()->after('promoted_by');
            $table->index(['campaign_id', 'is_referral_node']);
        });

        Schema::table('public_tokens', function (Blueprint $table) {
            $table->text('token_ciphertext')->nullable()->after('token_hash');
            $table->foreignId('created_by')->nullable()->after('owner_person_id')->constrained('users')->nullOnDelete();
            $table->index(['campaign_id', 'owner_person_id', 'revoked_at']);
        });

        DB::table('persons')
            ->whereIn('id', DB::table('public_tokens')->whereNotNull('owner_person_id')->select('owner_person_id'))
            ->update(['is_referral_node' => true, 'promoted_at' => now()]);

        $slugs = ['technical-administrator', 'manager', 'territorial-coordination'];
        DB::table('campaign_roles')->whereIn('slug', $slugs)->get()->each(function ($role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (! in_array('territorial.tokens.manage', $permissions, true)) {
                $permissions[] = 'territorial.tokens.manage';
                DB::table('campaign_roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values($permissions)),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('public_tokens', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'owner_person_id', 'revoked_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('token_ciphertext');
        });
        Schema::table('persons', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'is_referral_node']);
            $table->dropConstrainedForeignId('promoted_by');
            $table->dropColumn(['is_referral_node', 'promoted_at']);
        });
    }
};
