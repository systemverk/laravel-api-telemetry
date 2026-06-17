<?php

namespace Systemverk\LaravelApiTelemetry\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Systemverk\LaravelApiTelemetry\ApiTelemetryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ApiTelemetryServiceProvider::class];
    }
}
