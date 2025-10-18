<?php

declare(strict_types=1);

use MoeMizrak\LaravelLogReader\Enums\LogDriverType;
use MoeMizrak\LaravelLogReader\Enums\LogTableColumnType;

return [
    /*
    |--------------------------------------------------------------------------
    | Enable or disable the MCP Log Reader Tool
    |--------------------------------------------------------------------------
    */
    'enabled' => env('MCP_LOG_READER_TOOL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Log Reader Integration
    |--------------------------------------------------------------------------
    | Here you can configure the log reader settings.
    |--------------------------------------------------------------------------
    */
    'log_reader' => [
        'driver' => env('LOG_READER_DRIVER', LogDriverType::FILE->value),

        'file' => [
            'path' => env('LOG_FILE_PATH', storage_path('logs/laravel.log')),
            'chunk_size' => env('LOG_READER_FILE_CHUNK_SIZE', 512 * 1024), // 512KB for file reading
        ],

        'db' => [
            'table' => env('LOG_DB_TABLE_NAME', 'logs'),
            'connection' => env('LOG_DB_CONNECTION'),
            'chunk_size' => env('LOG_READER_DB_CHUNK_SIZE', 500),

            // Column mapping: maps DB columns to LogData properties
            'columns' => [
                LogTableColumnType::ID->value => 'id',
                LogTableColumnType::LEVEL->value => 'level', // e.g. 'ERROR', 'INFO'
                LogTableColumnType::MESSAGE->value => 'message', // main log message
                LogTableColumnType::TIMESTAMP->value => 'created_at', // time of the log entry (e.g. 'created_at' or 'logged_at')
                LogTableColumnType::CHANNEL->value => 'channel', // e.g. 'production', 'local'
                LogTableColumnType::CONTEXT->value => 'context', // additional context info, often JSON e.g. '{"action":"UserLogin"}'
                LogTableColumnType::EXTRA->value => 'extra', // any extra data, often JSON e.g. '{"ip":172.0.0.1, "session_id":"abc", "user_id":123}'
            ],

            // Columns that should be searchable in DB queries
            'searchable_columns' => [
                LogTableColumnType::MESSAGE->value,
                LogTableColumnType::CONTEXT->value,
                LogTableColumnType::EXTRA->value,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Tool Metadata
    |--------------------------------------------------------------------------
    | These fields define how the tool is exposed to the LLM client.
    |--------------------------------------------------------------------------
    */
    'tool' => [
        'name' => env('MCP_LOG_READER_TOOL_NAME', 'log-reader'),
        'title' => env('MCP_LOG_READER_TOOL_TITLE', 'Log Reader Tool'),
        'description' => env(
            'MCP_LOG_READER_TOOL_DESCRIPTION',
            'A tool to read and analyze application log data via MCP.'
        ),
    ],
];
