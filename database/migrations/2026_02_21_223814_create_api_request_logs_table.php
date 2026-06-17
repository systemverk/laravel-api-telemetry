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
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('requested_at')->index();
            $table->string('method', 16);
            $table->string('path', 2048);
            $table->string('route_name')->nullable()->index();
            $table->unsignedSmallInteger('status_code')->index();
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamps();

            $table->index(['requested_at', 'status_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
