<?php

namespace Systemverk\LaravelApiTelemetry\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Systemverk\LaravelApiTelemetry\Models\ApiRequestLog;
use Systemverk\LaravelApiTelemetry\Support\ApiRequestLogBuffer;

class FlushApiRequestLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-logs:flush {--max-minutes=5 : Minutes to scan backwards from now}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush buffered API request logs from Redis into database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('api_request_logging.enabled', true)) {
            return self::SUCCESS;
        }

        $maxMinutes = max(1, (int) $this->option('max-minutes'));
        $totalInserted = 0;

        foreach ($this->keysToProcess($maxMinutes) as $key) {
            $inserted = $this->flushKey($key);
            $totalInserted += $inserted;
        }

        $this->info("Flushed {$totalInserted} API request logs.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function keysToProcess(int $maxMinutes): array
    {
        $keys = [];

        for ($i = 0; $i < $maxMinutes; $i++) {
            $keys[] = ApiRequestLogBuffer::keyForMinute(now()->utc()->subMinutes($i));
        }

        return $keys;
    }

    private function flushKey(string $key): int
    {
        $connection = Redis::connection(ApiRequestLogBuffer::redisConnection());

        if ((int) $connection->llen($key) === 0) {
            return 0;
        }

        $processingKey = $key.':processing:'.Str::uuid();

        if (! $connection->renamenx($key, $processingKey)) {
            return 0;
        }

        try {
            $entries = $connection->lrange($processingKey, 0, -1);
            $rows = [];

            foreach ($entries as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                $decoded = json_decode($entry, true);

                if (! is_array($decoded)) {
                    continue;
                }

                $row = ApiRequestLogBuffer::prepareForInsert($decoded);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            if (empty($rows)) {
                $connection->del($processingKey);

                return 0;
            }

            $batchSize = max(1, (int) config('api_request_logging.flush_batch_size', 1000));

            foreach (array_chunk($rows, $batchSize) as $chunk) {
                ApiRequestLog::query()->insert($chunk);
            }

            $connection->del($processingKey);

            return count($rows);
        } catch (\Throwable $exception) {
            Log::error('Failed to flush buffered API request logs.', [
                'key' => $key,
                'processing_key' => $processingKey,
                'error' => $exception->getMessage(),
            ]);

            $connection->expire($processingKey, ApiRequestLogBuffer::redisTtlSeconds());

            return 0;
        }
    }
}
