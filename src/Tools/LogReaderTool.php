<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Tool;
use MoeMizrak\LaravelLogReader\Data\LogData;
use MoeMizrak\LaravelLogReader\Facades\LogReader;
use Throwable;

final class LogReaderTool extends Tool
{
    protected string $name;
    protected string $title;
    protected string $description;

    public function __construct()
    {
        $this->name = config('laravel-mcp-log.tool.name');
        $this->title = config('laravel-mcp-log.tool.title');
        $this->description = config('laravel-mcp-log.tool.description');
    }

    public function handle(array $input = []): array
    {
        try {
            $query = (string) Arr::get($input, 'query', '');
            $filters = (array) Arr::get($input, 'filters', []);

            // Normalize date filters
            foreach (['date_from', 'date_to'] as $key) {
                if (isset($filters[$key])) {
                    $filters[$key] = $this->normalizeDate($filters[$key]);
                }
            }

            $reader = LogReader::filter($filters);

            if ($query !== '') {
                $reader = $reader->search($query);
            }

            $reader = $reader->chunk();

            $results = $reader->execute();

            /** @var array<int, array<string, mixed>> $data */
            $data = array_map(static fn (LogData $log): array => $log->toArray(), $results);

            return [
                'success' => true,
                'data' => $data,
                'count' => count($data),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to read logs. Check server logs for details.',
            ];
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Free text search in logs.',
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Filter by log fields.',
                    'properties' => [
                        'level' => ['type' => 'string', 'description' => 'Log level (error, info, etc.)'],
                        'date_from' => ['type' => 'string', 'format' => 'date'],
                        'date_to' => ['type' => 'string', 'format' => 'date'],
                        'channel' => ['type' => 'string', 'description' => 'Log channel name e.g. "production", "local" etc.'],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Determines if the tool should be registered.
     */
    public function shouldRegister(): bool
    {
        return (bool) config('laravel-mcp-log.enabled', true);
    }

    /**
     * Normalize a date string into YYYY-MM-DD format if valid, or return null.
     */
    private function normalizeDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}