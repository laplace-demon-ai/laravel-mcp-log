<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tests\Tools;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaplaceDemonAI\LaravelMcpLog\Tests\TestCase;
use LaplaceDemonAI\LaravelMcpLog\Tools\LogReaderTool;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\Text as McpTextContent;
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
                LogTableColumnType::MESSAGE->value,
                LogTableColumnType::CONTEXT->value,
                LogTableColumnType::EXTRA->value,
            ],

            'laravel-mcp-log.tool.name' => 'log-reader',
            'laravel-mcp-log.tool.title' => 'Log Reader Tool',
            'laravel-mcp-log.tool.description' => 'A tool to read and analyze application log data via MCP.',
        ]);

        $this->tool = new LogReaderTool;
    }

    #[Test]
    public function it_respects_enabled_flag(): void
    {
        /* SETUP */
        config(['laravel-mcp-log.enabled' => false]);

        /* ASSERT */
        $this->assertFalse($this->tool->shouldRegister());
    }

    #[Test]
    public function it_returns_success_response_with_data(): void
    {
        /* SETUP */
        $input = ['query' => 'User logged in', 'filters' => []];

        /* EXECUTE */
        $response = $this->tool->handle($input);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(1, $data);
        $this->assertSame('User logged in', $data[0]['message']);
    }

    #[Test]
    public function it_returns_success_with_empty_query_and_filters(): void
    {
        /* SETUP */
        $input = ['query' => '', 'filters' => []];

        /* EXECUTE */
        $response = $this->tool->handle($input);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(3, $data);
    }

    #[Test]
    public function it_applies_filters_and_returns_matching_results(): void
    {
        /* SETUP */
        $input = ['query' => '', 'filters' => [FilterKeyType::LEVEL->value => 'error']];

        /* EXECUTE */
        $response = $this->tool->handle($input);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
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
        $responseNone = $this->tool->handle(['filters' => [FilterKeyType::DATE_TO->value => $yesterday]]);
        $responseAll = $this->tool->handle(['filters' => [FilterKeyType::DATE_FROM->value => $yesterday]]);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $responseNone);
        $this->assertInstanceOf(Response::class, $responseAll);
        $this->assertCount(0, $this->extractJsonArrayFromResponse($responseNone));
        $this->assertCount(3, $this->extractJsonArrayFromResponse($responseAll));
    }

    #[Test]
    public function it_returns_error_when_reader_fails(): void
    {
        /* SETUP */
        config(['laravel-log-reader.db.table' => 'invalid_table']);

        /* EXECUTE */
        $response = $this->tool->handle(['query' => '', 'filters' => []]);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
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
        $response = $this->tool->handle(['query' => '', 'filters' => []]);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
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
        $response = $this->tool->handle(['query' => 'failure', 'filters' => []]);

        /* ASSERT */
        $this->assertInstanceOf(Response::class, $response);
        $data = $this->extractJsonArrayFromResponse($response);
        $this->assertCount(1, $data);
        $this->assertSame('Second entry {"context":"failure"}', $data[0]['message']);

        unlink($logFile);
    }

    /* ------------------------- Helpers ------------------------- */

    private function responseText(Response $response): string
    {
        // 1) Try a conventional accessor if present
        if (method_exists($response, 'content')) {
            $content = $response->content();

            // Some implementations may return string content directly
            if (is_string($content)) {
                return $content;
            }

            // If it's the MCP Text content object, try the common access patterns
            if ($content instanceof McpTextContent) {
                // Prefer a public getter if available
                if (method_exists($content, 'text')) {
                    return (string) $content->text();
                }

                // Fallback to array shape if provided
                if (method_exists($content, 'toArray')) {
                    $arr = $content->toArray();
                    if (is_array($arr) && isset($arr['text'])) {
                        return (string) $arr['text'];
                    }
                }
            }

            // Generic fallback: try toArray() for any content type
            if (is_object($content) && method_exists($content, 'toArray')) {
                $arr = $content->toArray();
                if (is_array($arr) && isset($arr['text'])) {
                    return (string) $arr['text'];
                }
            }
        }

        // 2) Reflection fallback (matches your dd() shape: protected #content->#text)
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

        // 3) Absolute last resort
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
            context TEXT,
            extra TEXT,
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
