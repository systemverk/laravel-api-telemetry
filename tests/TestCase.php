<?php

namespace Systemverk\LaravelApiTelemetry\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;
use Systemverk\LaravelApiTelemetry\ApiTelemetryServiceProvider;
use Systemverk\LaravelApiTelemetry\Tests\Support\FakeRedisConnection;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ApiTelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate')->run();
    }

    /**
     * Swap the Redis facade for an in-memory double and return it.
     */
    protected function fakeRedis(): FakeRedisConnection
    {
        $fake = new FakeRedisConnection;

        Redis::shouldReceive('connection')->andReturn($fake);

        return $fake;
    }

    /**
     * @return \Illuminate\Foundation\Application
     */
    protected function app(): Application
    {
        /** @var Application $app */
        $app = $this->app;

        return $app;
    }
}
