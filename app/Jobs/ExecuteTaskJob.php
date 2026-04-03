<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueLog;
use App\Services\ClaudeCodeService;
use App\Services\IssueService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public int $issueNumber,
        public ?string $feedbackComment = null
    ) {
        $this->onQueue('sdd');
    }

    public function handle(
        IssueService $issueService,
        ClaudeCodeService $claudeService,
        SlackService $slackService,
        PreviewService $previewService,
    ): void {
        $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();

        $isResume = $this->feedbackComment !== null;
        $featureBranch = $issue->feature_branch ?? "feature/issue-{$this->issueNumber}";

        if (!$issue->feature_branch) {
            $issueService->saveFeatureBranch($issue, $featureBranch);
        }

        $issueService->transitionTo($issue, IssueStatus::Developing);

        $issueWorkspacePath = $previewService->issueWorkspacePath($this->issueNumber);

        if (!$isResume) {
            $previewService->setup($this->issueNumber, $featureBranch);
        }

        $prompt = $isResume
            ? $this->buildResumePrompt($issue, $this->feedbackComment)
            : $this->buildFirstRunPrompt($issue, $featureBranch);

        $sessionId = $issue->dev_session_id;

        $result = $claudeService->execute($prompt, $issueWorkspacePath, $sessionId);

        if ($result['session_id']) {
            $issueService->saveDevSessionId($issue, $result['session_id']);
        }

        IssueLog::create([
            'issue_id' => $issue->id,
            'job_type' => 'execute_task',
            'session_id' => $result['session_id'],
            'prompt' => $prompt,
            'output' => $result['output'],
            'exit_code' => $result['exit_code'],
            'duration_seconds' => $result['duration_seconds'],
        ]);

        $slackService->notifyPm(
            $issue->github_author,
            "Issue #{$this->issueNumber} updated based on your feedback. Preview refreshed."
        );
    }

    private function buildFirstRunPrompt(Issue $issue, string $featureBranch): string
    {
        return <<<PROMPT
            You are implementing a feature based on the following spec.

            ## Issue #{$issue->github_issue_number}: {$issue->title}

            {$issue->body}

            ## Instructions

            1. Create feature branch: {$featureBranch}
            2. Analyze the requirements
            3. Implement the feature in the waltily and/or waltily-frontend repos
            4. Write tests if applicable
            5. Commit all changes
            PROMPT;
    }

    private function buildResumePrompt(Issue $issue, string $feedback): string
    {
        return <<<PROMPT
            The PM has provided feedback on your implementation for issue #{$issue->github_issue_number}.

            ## PM Feedback

            {$feedback}

            ## Instructions

            Address the feedback, update the implementation, commit changes.
            PROMPT;
    }
}
