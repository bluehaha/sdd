<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueLog;
use App\Services\ClaudeCodeService;
use App\Services\GitHubService;
use App\Services\IssueService;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateSpecJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public int $issueNumber
    ) {
        $this->onQueue('sdd');
    }

    public function handle(
        IssueService $issueService,
        ClaudeCodeService $claudeService,
        GitHubService $githubService,
        SlackService $slackService,
    ): void {
        $issue = Issue::where('issue_number', $this->issueNumber)->firstOrFail();
        $issueService->transitionTo($issue, IssueStatus::SpecValidating);

        $sddRepo = config('sdd.github.sdd_repo');

        $prompt = $this->buildPrompt($issue);
        $sessionId = $issue->spec_session_id;
        $workingDirectory = config('sdd.workspace_path') . "/main";

        $result = $claudeService->execute($prompt, $workingDirectory, $sessionId);

        if ($result['session_id']) {
            $issueService->saveSpecSessionId($issue, $result['session_id']);
        }

        IssueLog::create([
            'issue_id' => $issue->id,
            'job_type' => 'validate_spec',
            'session_id' => $result['session_id'],
            'prompt' => $prompt,
            'output' => $result['output'],
            'exit_code' => $result['exit_code'],
            'duration_seconds' => $result['duration_seconds'],
        ]);

        $parsed = json_decode($result['output'], true);
        $innerResult = json_decode($parsed['result'] ?? '{}', true);
        $passed = $innerResult['passed'] ?? false;

        if ($passed) {
            $issueService->transitionTo($issue, IssueStatus::SpecPassed);
            $githubService->removeLabel($sddRepo, $this->issueNumber, 'spec_ready');
            $githubService->addLabel($sddRepo, $this->issueNumber, 'spec_pass');
            $githubService->postComment($sddRepo, $this->issueNumber, "Spec validated. Development will begin shortly.");
            $slackService->notifyPm($issue->github_author, "Spec for issue #{$this->issueNumber} passed validation. Development starting.");
        } else {
            $feedback = $innerResult['feedback'] ?? 'Please provide more details.';
            $issueService->transitionTo($issue, IssueStatus::Pending);
            $githubService->postComment($sddRepo, $this->issueNumber, "Spec needs clarification:\n\n{$feedback}");
            $githubService->removeLabel($sddRepo, $this->issueNumber, 'spec_ready');
            $slackService->notifyPm($issue->github_author, "Spec for issue #{$this->issueNumber} needs clarification: {$feedback}");
        }
    }

    private function buildPrompt(Issue $issue): string
    {
        return <<<PROMPT
            You are reviewing a feature spec for clarity and completeness.

            ## Issue #{$issue->issue_number}: {$issue->title}

            {$issue->body}

            ## Instructions

            Analyze this spec and determine if it is clear enough to implement.
            Check for: clear feature description, testable acceptance criteria, defined scope.

            Respond with JSON (no contain '```JSON'):
            {"passed": true/false, "summary": "...", "feedback": "...if not passed..."}
            PROMPT;
    }
}
