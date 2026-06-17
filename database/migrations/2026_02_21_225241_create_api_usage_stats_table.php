<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_key', 32);
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->unsignedBigInteger('responses_2xx')->default(0);
            $table->unsignedBigInteger('responses_4xx')->default(0);
            $table->unsignedBigInteger('responses_5xx')->default(0);
            $table->timestamps();

            $table->unique(['period_type', 'period_start', 'actor_key'], 'api_usage_stats_period_actor_unique');
            $table->index(['period_type', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usage_stats');
    }
};
