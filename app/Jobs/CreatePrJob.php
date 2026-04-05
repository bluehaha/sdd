<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Services\GitHubService;
use App\Services\IssueService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreatePrJob implements ShouldQueue
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
        GitHubService $githubService,
        SlackService $slackService,
        PreviewService $previewService,
    ): void {
        $issue = Issue::where('issue_number', $this->issueNumber)->firstOrFail();
        $sddRepo = config('sdd.github.sdd_repo');
        $targetRepos = config('sdd.github.target_repos');
        $commitMessage = "feat: issue #{$this->issueNumber} {$issue->title}";

        if (!$issue->feature_branch) {
            Log::error("Issue #{$this->issueNumber} has no feature branch set, cannot create PRs.");
            return;
        }

        $prLinks = [];

        foreach ($targetRepos as $repo) {
            try {
                $repoPath = $previewService->issueWorkspacePath($this->issueNumber) . "/{$repo}";

                if (!$githubService->hasChanges($repoPath)) {
                    Log::info("No changes in {$repo} for issue #{$this->issueNumber}, skipping PR.");
                    continue;
                }

                $githubService->stageAll($repoPath);
                $githubService->commit($repoPath, $commitMessage);
                $githubService->push($repo, $repoPath, $issue->feature_branch);

                $pr = $githubService->createPullRequest(
                    $repo,
                    $issue->feature_branch,
                    'main',
                    "[SDD #{$this->issueNumber}] {$issue->title}",
                    "Auto-generated PR for SDD issue #{$this->issueNumber}\n\n{$issue->title}"
                );
                $prLinks[] = "[{$repo} PR #{$pr['number']}]({$pr['html_url']})";
            } catch (Exception $e) {
                $token = config('sdd.github.token');
                $safeMessage = $token ? str_replace($token, '***', $e->getMessage()) : $e->getMessage();
                Log::warning("Failed to create PR for {$repo}: {$safeMessage}");
            }
        }

        if (empty($prLinks)) {
            throw new RuntimeException("Issue #{$this->issueNumber}: commits were made but all PRs failed to create.");
        }

        $issueService->transitionTo($issue, IssueStatus::Approved);

        $prList = implode("\n", $prLinks);
        $comment = "PRs created:\n\n{$prList}";
        $githubService->postComment($sddRepo, $this->issueNumber, $comment);

        $slackService->notifyPm(
            $issue->github_author,
            "Issue #{$this->issueNumber} approved. PR created: {$prList}"
        );
    }
}
