<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ClaudeCodeService
{
    private string $binaryPath;
    private int $maxTurns;
    private int $timeout;

    public function __construct(
        ?string $binaryPath = null,
        ?int $maxTurns = null,
        ?int $timeout = null
    ) {
        $this->binaryPath = $binaryPath ?? config('sdd.claude.binary_path', 'claude');
        $this->maxTurns = $maxTurns ?? config('sdd.claude.max_turns', 50);
        $this->timeout = $timeout ?? config('sdd.claude.timeout', 3600);
    }

    public function execute(string $prompt, string $workingDirectory, ?string $sessionId = null): array
    {
        $command = $this->buildCommand($prompt, $sessionId);

        $process = new Process($command);
        $process->setWorkingDirectory($workingDirectory);
        $process->setTimeout($this->timeout);

        $startTime = time();
        $process->run();
        $duration = time() - $startTime;

        $output = $process->getOutput();

        $logContext = [
            'command' => $command,
            'working_directory' => $workingDirectory,
            'exit_code' => $process->getExitCode(),
            'duration' => $duration,
            'session_id' => $this->parseSessionId($output),
        ];

        if (!$process->isSuccessful()) {
            Log::error('Claude Code CLI failed', array_merge($logContext, [
                'error_output' => $process->getErrorOutput(),
            ]));
        } else {
            Log::info('Claude Code CLI executed', $logContext);
        }

        return [
            'output' => $output,
            'error_output' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'duration_seconds' => $duration,
            'session_id' => $this->parseSessionId($output),
        ];
    }

    public function buildCommand(string $prompt, ?string $sessionId): array
    {
        $command = [
            $this->binaryPath,
            '--print',
            '--output-format', 'json',
            '--max-turns', (string) $this->maxTurns,
            '--model', 'sonnet',
            '--permission-mode', 'acceptEdits',
        ];

        if ($sessionId) {
            $command[] = '--resume';
            $command[] = $sessionId;
        }

        $command[] = '-p';
        $command[] = $prompt;

        return $command;
    }

    public function parseSessionId(string $output): ?string
    {
        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded['session_id'] ?? null;
    }
}
