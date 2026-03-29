<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeCodeService;
use PHPUnit\Framework\TestCase;

class ClaudeCodeServiceTest extends TestCase
{
    public function test_build_command_without_resume(): void
    {
        $service = new ClaudeCodeService('claude', 50, 3600);

        $command = $service->buildCommand('Validate this spec', null);

        $this->assertEquals([
            'claude', '--print', '--output-format', 'json', '--max-turns', '50',
            '-p', 'Validate this spec',
        ], $command);
    }

    public function test_build_command_with_resume(): void
    {
        $service = new ClaudeCodeService('claude', 50, 3600);

        $command = $service->buildCommand('Fix the bug', 'session-abc-123');

        $this->assertEquals([
            'claude', '--print', '--output-format', 'json', '--max-turns', '50',
            '--resume', 'session-abc-123',
            '-p', 'Fix the bug',
        ], $command);
    }

    public function test_parse_session_id_from_output(): void
    {
        $service = new ClaudeCodeService('claude', 50, 3600);

        $output = json_encode([
            'session_id' => 'sess-xyz-789',
            'result' => 'done',
        ]);

        $sessionId = $service->parseSessionId($output);
        $this->assertEquals('sess-xyz-789', $sessionId);
    }

    public function test_parse_session_id_returns_null_on_invalid_output(): void
    {
        $service = new ClaudeCodeService('claude', 50, 3600);
        $sessionId = $service->parseSessionId('not json');
        $this->assertNull($sessionId);
    }
}
