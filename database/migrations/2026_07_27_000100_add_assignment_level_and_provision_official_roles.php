<?php

use App\Models\Campaign;
use App\Support\OfficialRoleProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_roles', function (Blueprint $table) {
            $table->unsignedSmallInteger('assignment_level')->default(0)->after('slug');
        });

        Campaign::query()->each(fn (Campaign $campaign) => app(OfficialRoleProvisioner::class)->provision($campaign));
    }

    public function down(): void
    {
        Schema::table('campaign_roles', function (Blueprint $table) {
            $table->dropColumn('assignment_level');
        });
    }
};
