<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
        $tableName = TelemetryConfig::usageStatsTable();

        Schema::connection($this->getConnection())->create($tableName, function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->string('period_type', 16);
            $table->date('period_start');

            if (TelemetryConfig::userIdType() === 'string') {
                $table->string('user_id', 64)->nullable()->index();
            } else {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }

            // Wide enough for "user:" plus a UUID/ULID identifier.
            $table->string('actor_key', 96);
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->unsignedBigInteger('responses_1xx')->default(0);
            $table->unsignedBigInteger('responses_2xx')->default(0);
            $table->unsignedBigInteger('responses_3xx')->default(0);
            $table->unsignedBigInteger('responses_4xx')->default(0);
            $table->unsignedBigInteger('responses_5xx')->default(0);
            $table->timestamps();

            $table->unique(['period_type', 'period_start', 'actor_key'], $tableName.'_period_actor_unique');
            $table->index(['period_type', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TelemetryConfig::usageStatsTable());
    }
};
