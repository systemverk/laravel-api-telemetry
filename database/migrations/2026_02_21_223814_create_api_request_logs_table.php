<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;
use Systemverk\LaravelApiTelemetry\Support\TelemetryConfig;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return TelemetryConfig::databaseConnection();
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create(TelemetryConfig::requestLogsTable(), function (Blueprint $table) {
            $table->id();
            $table->timestamp('requested_at')->index();
            $table->string('method', 16);
            $table->string('path', ApiRequestLogBuffer::MAX_PATH_LENGTH);
            $table->string('route_name')->nullable()->index();
            $table->unsignedSmallInteger('status_code')->index();
            $table->unsignedInteger('duration_ms');

            if (TelemetryConfig::userIdType() === 'string') {
                $table->string('user_id', 64)->nullable()->index();
            } else {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }

            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('user_agent', ApiRequestLogBuffer::MAX_USER_AGENT_LENGTH)->nullable();
            $table->string('request_id', ApiRequestLogBuffer::MAX_REQUEST_ID_LENGTH)->nullable()->index();
            $table->timestamps();

            // Serves the daily consolidation scan and status-code breakdowns.
            $table->index(['requested_at', 'status_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TelemetryConfig::requestLogsTable());
    }
};
