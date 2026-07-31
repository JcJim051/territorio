<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_service_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label');
            $table->text('credentials');
            $table->json('settings')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_service_credentials');
    }
};
