<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tests\Tools;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaplaceDemonAI\LaravelMcpLog\Tests\TestCase;
use LaplaceDemonAI\LaravelMcpLog\Tools\LogReaderTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\Text as McpTextContent;
use MoeMizrak\LaravelLogReader\Enums\ColumnType;
use MoeMizrak\LaravelLogReader\Enums\FilterKeyType;
use MoeMizrak\LaravelLogReader\Enums\LogDriverType;
use MoeMizrak\LaravelLogReader\Enums\LogTableColumnType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;

#[CoversClass(LogReaderTool::class)]
final class LogReaderToolTest extends TestCase
{
    use RefreshDatabase;

    private string $table = 'logs';
    private LogReaderTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLogsTable();
        $this->seedLogs();

        config([
            'laravel-log-reader.driver' => LogDriverType::DB->value,
            'laravel-log-reader.db.table' => $this->table,
            'laravel-log-reader.db.columns' => [
                LogTableColumnType::ID->value => 'id',
                LogTableColumnType::LEVEL->value => 'level',
                LogTableColumnType::MESSAGE->value => 'message',
                LogTableColumnType::TIMESTAMP->value => 'created_at',
                LogTableColumnType::CHANNEL->value => 'channel',
                LogTableColumnType::CONTEXT->value => 'context',
                LogTableColumnType::EXTRA->value => 'extra',
            ],
            'laravel-log-reader.db.searchable_columns' => [
                ['name' => LogTableColumnType::MESSAGE->value, 'type' => ColumnType::TEXT->value],
                ['name' => LogTableColumnType::CONTEXT->value, 'type' => ColumnType::JSON->value],
                ['name' => LogTableColumnType::EXTRA->value, 'type' => ColumnType::JSON->value],
            ],
            'laravel-mcp-log.tool.name' => 'log-reader',
            'laravel-mcp-log.tool.title' => 'Log Reader Tool',
            'laravel-mcp-log.tool.description' => 'A tool to read and analyze application log data via MCP.',
        ]);

        $this->tool = new LogReaderTool;
    }

    #[Test]
    public function it_respects_enabled_flag_for_either_server(): void
    {
        /* SETUP */
        config([
            'laravel-mcp-log.enabled' => false,
        ]);

        /* ASSERT */
        $this->assertFalse($this->tool->shouldRegister());
    }

    #[Test]
    public function it_returns_success_response_with_data(): void
    {
        /* SETUP */
        $request = new Request(['query' => 'User logged in', 'filters' => []]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(1, $data);
        $this->assertSame('User logged in', $data[0]['message']);
    }

    #[Test]
    public function it_returns_success_with_empty_query_and_filters(): void
    {
        /* SETUP */
        $request = new Request(['query' => '', 'filters' => []]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(3, $data);
    }

    #[Test]
    public function it_applies_filters_and_returns_matching_results(): void
    {
        /* SETUP */
        $request = new Request(['filters' => [FilterKeyType::LEVEL->value => 'error']]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(1, $data);
        $this->assertSame('Payment failed', $data[0]['message']);
    }

    #[Test]
    public function it_normalizes_date_filters(): void
    {
        /* SETUP */
        $yesterday = now()->subDay()->setTime(23, 59, 59)->toDateTimeString();

        /* EXECUTE */
        $responseNone = $this->tool->handle(new Request(['filters' => [FilterKeyType::DATE_TO->value => $yesterday]]));
        $responseAll = $this->tool->handle(new Request(['filters' => [FilterKeyType::DATE_FROM->value => $yesterday]]));

        /* ASSERT */
        $this->assertCount(0, $this->extractJsonArrayFromResponse($responseNone));
        $this->assertCount(3, $this->extractJsonArrayFromResponse($responseAll));
    }

    #[Test]
    public function it_returns_error_when_reader_fails(): void
    {
        /* SETUP */
        config(['laravel-log-reader.db.table' => 'invalid_table']);
        $request = new Request(['query' => '', 'filters' => []]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $text = $this->responseText($response);
        $this->assertStringContainsString('Failed to read logs. Check server logs for details.', $text);
    }

    #[Test]
    public function it_reads_logs_via_file_driver(): void
    {
        /* SETUP */
        $logFile = tempnam(sys_get_temp_dir(), 'mcp_log_');
        file_put_contents($logFile, <<<'LOGS'
[2025-09-28 12:00:00] local.INFO: First entry
[2025-09-28 12:01:00] local.ERROR: Second entry
LOGS);
        config([
            'laravel-log-reader.driver' => LogDriverType::FILE->value,
            'laravel-log-reader.file.path' => $logFile,
        ]);

        /* EXECUTE */
        $response = $this->tool->handle(new Request(['query' => '', 'filters' => []]));

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(2, $data);

        unlink($logFile);
    }

    #[Test]
    public function it_filters_logs_via_file_driver(): void
    {
        /* SETUP */
        $logFile = tempnam(sys_get_temp_dir(), 'mcp_log_');
        file_put_contents($logFile, <<<'LOGS'
[2025-09-28 12:00:00] local.INFO: First entry
[2025-09-28 12:01:00] local.ERROR: Second entry {"context":"failure"}
LOGS);
        config([
            'laravel-log-reader.driver' => LogDriverType::FILE->value,
            'laravel-log-reader.file.path' => $logFile,
        ]);

        /* EXECUTE */
        $response = $this->tool->handle(new Request(['query' => 'failure', 'filters' => []]));

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(1, $data);
        $this->assertSame('Second entry {"context":"failure"}', $data[0]['message']);

        unlink($logFile);
    }

    #[Test]
    public function it_returns_error_on_invalid_date_format(): void
    {
        /* SETUP */
        $request = new Request(['filters' => ['date_from' => 'not-a-date']]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $text = $this->responseText($response);
        $this->assertStringContainsString('Validation failed:', $text);
    }

    #[Test]
    public function it_returns_error_when_date_to_is_before_date_from(): void
    {
        /* SETUP */
        $request = new Request(['filters' => ['date_from' => '2025-01-10', 'date_to' => '2025-01-01']]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $text = $this->responseText($response);
        $this->assertStringContainsString('Validation failed:', $text);
    }

    #[Test]
    public function it_returns_error_when_filters_is_not_array(): void
    {
        /* SETUP */
        $request = new Request(['filters' => 'not-an-array']);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $text = $this->responseText($response);
        $this->assertStringContainsString('Validation failed:', $text);
    }

    #[Test]
    public function it_respects_db_limit_and_does_not_exceed_max_records(): void
    {
        /* SETUP */
        config([
            'laravel-log-reader.driver' => LogDriverType::DB->value,
            'laravel-log-reader.db.limit' => 50,
        ]);
        $rows = [];
        $base = now()->subHours(1);
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = [
                'level' => 'info',
                'message' => "Generated log #{$i}",
                'channel' => 'test',
                'context' => '{}',
                'extra' => '{}',
                'created_at' => $base->copy()->addSeconds($i),
            ];
        }
        DB::table($this->table)->insert($rows);
        $request = new Request(['query' => '', 'filters' => []]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(50, $data);
    }

    #[Test]
    public function it_limits_results_for_file_driver(): void
    {
        /* SETUP */
        $logFile = tempnam(sys_get_temp_dir(), 'mcp_log_');

        // Create a file with far more lines than the limit
        $lines = [];
        $base = now()->subHours(1);
        for ($i = 1; $i <= 120; $i++) {
            $ts = $base->copy()->addSeconds($i)->format('Y-m-d H:i:s');
            $level = $i % 2 === 0 ? 'ERROR' : 'INFO';
            $lines[] = "[{$ts}] local.{$level}: Entry {$i}";
        }
        file_put_contents($logFile, implode(PHP_EOL, $lines));
        config([
            'laravel-log-reader.driver' => LogDriverType::FILE->value,
            'laravel-log-reader.file.path' => $logFile,
            'laravel-log-reader.file.limit' => 50,
        ]);
        $request = new Request(['query' => '', 'filters' => []]);

        /* EXECUTE */
        $response = $this->tool->handle($request);

        /* ASSERT */
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(50, $data);

        unlink($logFile);
    }

    /* ------------------------- Helpers ------------------------- */

    private function responseText(Response $response): string
    {
        if (method_exists($response, 'content')) {
            $content = $response->content();

            if (is_string($content)) {
                return $content;
            }

            if ($content instanceof McpTextContent) {
                if (method_exists($content, 'text')) {
                    return (string) $content->text();
                }

                if (method_exists($content, 'toArray')) {
                    $arr = $content->toArray();

                    if (is_array($arr) && isset($arr['text'])) {
                        return (string) $arr['text'];
                    }
                }
            }

            if (is_object($content) && method_exists($content, 'toArray')) {
                $arr = $content->toArray();

                if (is_array($arr) && isset($arr['text'])) {
                    return (string) $arr['text'];
                }
            }
        }

        $ref = new ReflectionObject($response);

        if ($ref->hasProperty('content')) {
            $prop = $ref->getProperty('content');
            $prop->setAccessible(true);
            $content = $prop->getValue($response);

            if (is_string($content)) {
                return $content;
            }

            if ($content instanceof McpTextContent) {
                $cref = new ReflectionObject($content);

                if ($cref->hasProperty('text')) {
                    $p = $cref->getProperty('text');
                    $p->setAccessible(true);
                    $text = $p->getValue($content);

                    if (is_string($text)) {
                        return $text;
                    }
                }
            }

            if (is_object($content)) {
                if (method_exists($content, '__toString')) {
                    return (string) $content;
                }
                if (method_exists($content, 'toArray')) {
                    $arr = $content->toArray();
                    if (is_array($arr) && isset($arr['text'])) {
                        return (string) $arr['text'];
                    }
                }
            }
        }

        if (method_exists($response, '__toString')) {
            return (string) $response;
        }

        $this->fail('Unable to read text content from MCP Response object.');
    }

    private function extractJsonArrayFromResponse(Response $response): array
    {
        $text = $this->responseText($response);

        if (preg_match('/(\[[\s\S]*\])\s*$/', $text, $m) === 1) {
            $decoded = json_decode($m[1], true);
            $this->assertIsArray($decoded, 'Response did not contain valid JSON array payload.');

            return $decoded ?? [];
        }

        $this->fail('No JSON array found in tool response text.');
    }

    private function createLogsTable(): void
    {
        DB::statement("CREATE TABLE {$this->table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level VARCHAR(20),
            message TEXT,
            channel VARCHAR(50),
            context JSON,
            extra JSON,
            created_at DATETIME
        )");
    }

    private function seedLogs(): void
    {
        DB::table($this->table)->insert([
            [
                'level' => 'info',
                'message' => 'User logged in',
                'channel' => 'auth',
                'context' => '{"action":"login"}',
                'extra' => '{"user_id":1}',
                'created_at' => now()->subMinutes(10),
            ],
            [
                'level' => 'error',
                'message' => 'Payment failed',
                'channel' => 'payment',
                'context' => '{}',
                'extra' => '{"user_id":2}',
                'created_at' => now()->subMinutes(5),
            ],
            [
                'level' => 'debug',
                'message' => 'Cache cleared',
                'channel' => 'system',
                'context' => '{}',
                'extra' => '{}',
                'created_at' => now(),
            ],
        ]);
    }
}
