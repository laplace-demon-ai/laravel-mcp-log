<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaplaceDemonAI\LaravelMcpLog\LaravelMcpLogServiceProvider;

/**
 * Base test case for the package.
 */
class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @return string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelMcpLogServiceProvider::class,
        ];
    }
}