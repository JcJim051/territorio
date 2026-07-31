<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('address')->nullable()->after('location');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->text('location_notes')->nullable()->after('longitude');
        });

        DB::table('meetings')
            ->whereNull('address')
            ->whereNotNull('location')
            ->update(['address' => DB::raw('location')]);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['address', 'latitude', 'longitude', 'location_notes']);
        });
    }
};
