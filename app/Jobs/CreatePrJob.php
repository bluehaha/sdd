<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Services\GitHubService;
use App\Services\IssueService;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreatePrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $issueNumber
    ) {
        $this->onQueue('sdd');
    }

    public function handle(): void
    {
        $issueService = app(IssueService::class);
        $githubService = app(GitHubService::class);
        $slackService = app(SlackService::class);

        $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();
        $sddRepo = config('sdd.github.sdd_repo');
        $targetRepos = config('sdd.github.target_repos');

        $prLinks = [];

        foreach ($targetRepos as $repo) {
            try {
                $pr = $githubService->createPullRequest(
                    $repo,
                    $issue->feature_branch,
                    'develop',
                    "[SDD #{$this->issueNumber}] {$issue->title}",
                    "Auto-generated PR for SDD issue #{$this->issueNumber}\n\n{$issue->body}"
                );
                $prLinks[] = "[{$repo} PR #{$pr['number']}]({$pr['html_url']})";
            } catch (\Exception $e) {
                Log::warning("Failed to create PR for {$repo}: {$e->getMessage()}");
            }
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
