<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Response;
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

    /**
     * @param array{
     *    query?: string,
     *    filters?: array{
     *       level?: string,
     *       date_from?: string,
     *       date_to?: string,
     *       channel?: string,
     *    }
     * } $input
     */
    public function handle(array $input = []): Response
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

            // Convert data to JSON string
            $dataJson = json_encode($data);

            return Response::text(<<<MARKDOWN
            Here is the log data you requested:

            $dataJson
            MARKDOWN);
        } catch (Throwable $e) {
            return Response::error('Failed to read logs. Check server logs for details.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free text search in logs.')
                ->nullable(),

            'filters' => $schema->object([
                'level' => $schema->string()->description('Log level (error, info, etc.)')->nullable(),
                'date_from' => $schema->string()->description('Start date (YYYY-MM-DD)')->nullable(),
                'date_to' => $schema->string()->description('End date (YYYY-MM-DD)')->nullable(),
                'channel' => $schema->string()->description('Log channel, e.g. "production", "local"')->nullable(),
            ])
                ->description('Optional filters applied to logs.')
                ->nullable(),
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
