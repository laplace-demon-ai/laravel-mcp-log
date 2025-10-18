<?php

declare(strict_types=1);

namespace LaplaceDemonAI\LaravelMcpLog\Tests\Tools;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LaplaceDemonAI\LaravelMcpLog\Tests\TestCase;
use LaplaceDemonAI\LaravelMcpLog\Tools\LogReaderTool;
use MoeMizrak\LaravelLogReader\Enums\FilterKeyType;
use MoeMizrak\LaravelLogReader\Enums\LogDriverType;
use MoeMizrak\LaravelLogReader\Enums\LogTableColumnType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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
        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['count']);
        $this->assertSame('User logged in', $response['data'][0]['message']);
    }

    #[Test]
    public function it_returns_success_with_empty_query_and_filters(): void
    {
        /* SETUP */
        $input = ['query' => '', 'filters' => []];

        /* EXECUTE */
        $response = $this->tool->handle($input);

        /* ASSERT */
        $this->assertTrue($response['success']);
        $this->assertSame(3, $response['count']);
    }

    #[Test]
    public function it_applies_filters_and_returns_matching_results(): void
    {
        /* SETUP */
        $input = ['query' => '', 'filters' => [FilterKeyType::LEVEL->value => 'error']];

        /* EXECUTE */
        $response = $this->tool->handle($input);

        /* ASSERT */
        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['count']);
        $this->assertSame('Payment failed', $response['data'][0]['message']);
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
        $this->assertSame(0, $responseNone['count']);
        $this->assertSame(3, $responseAll['count']);
    }

    #[Test]
    public function it_returns_error_when_reader_fails(): void
    {
        /* SETUP */
        config(['laravel-log-reader.db.table' => 'invalid_table']);

        /* EXECUTE */
        $response = $this->tool->handle(['query' => '', 'filters' => []]);

        /* ASSERT */
        $this->assertFalse($response['success']);
        $this->assertSame('Failed to read logs. Check server logs for details.', $response['error']);
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
        $this->assertTrue($response['success']);
        $this->assertSame(2, $response['count']);

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
        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['count']);
        $this->assertSame('Second entry {"context":"failure"}', $response['data'][0]['message']);

        // Teardown
        unlink($logFile);
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
