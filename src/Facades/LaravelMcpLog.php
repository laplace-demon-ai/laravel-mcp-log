<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaplaceDemonAI\LaravelMcpLog\LaravelMcpLog
 */
final class LaravelMcpLog extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \LaplaceDemonAI\LaravelMcpLog\LaravelMcpLog::class;
    }
}
