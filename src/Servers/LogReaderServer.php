<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Servers;

use LaplaceDemonAI\LaravelMcpLog\Tools\LogReaderTool;
use Laravel\Mcp\Server;

final class LogReaderServer extends Server
{
    protected string $name = 'Log Reader Server';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server allows AI agents to read and analyze application log data.
    MARKDOWN;

    /**
     * {@inheritDoc}
     */
    protected array $tools = [
        LogReaderTool::class,
    ];
}