<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\PreviewEnvironment;
use App\Services\DbCloneService;
use App\Services\GitHubService;
use App\Services\IssueService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SetupPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $issueNumber
    ) {
        $this->onQueue('sdd');
    }

    public function handle(): void
    {
        $issueService = app(IssueService::class);
        $previewService = app(PreviewService::class);
        $dbCloneService = app(DbCloneService::class);
        $githubService = app(GitHubService::class);
        $slackService = app(SlackService::class);

        $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();
        $sddRepo = config('sdd.github.sdd_repo');

        $clonedDbName = null;
        if ($this->hasMigrationFiles($previewService->workspacePath($this->issueNumber))) {
            $clonedDbName = $dbCloneService->cloneForIssue($this->issueNumber);
        }

        $subdomain = $previewService->setup(
            $this->issueNumber,
            $issue->feature_branch,
            $clonedDbName
        );

        PreviewEnvironment::updateOrCreate(
            ['issue_id' => $issue->id],
            [
                'subdomain' => $subdomain,
                'workspace_path' => $previewService->workspacePath($this->issueNumber),
                'cloned_db_name' => $clonedDbName,
            ]
        );

        $issueService->transitionTo($issue, IssueStatus::PreviewReady);

        $previewUrl = "https://{$subdomain}";
        $githubService->addLabel($sddRepo, $this->issueNumber, 'feature_done');
        $githubService->postComment($sddRepo, $this->issueNumber, "Preview environment ready: {$previewUrl}");
        $slackService->notifyPm($issue->github_author, "Issue #{$this->issueNumber} development complete. Preview: {$previewUrl}");
    }

    private function hasMigrationFiles(string $workspacePath): bool
    {
        $migrationDir = "{$workspacePath}/waltily/database/migrations";

        if (!is_dir($migrationDir)) {
            return false;
        }

        $files = glob("{$migrationDir}/*.php");

        return count($files) > 0;
    }
}
