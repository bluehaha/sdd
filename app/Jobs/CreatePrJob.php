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
use Symfony\Component\Process\Process;

class CreatePrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /** @var callable|null */
    private $gitRunner = null;

    public function __construct(
        public int $issueNumber
    ) {
        $this->onQueue('sdd');
    }

    /**
     * @internal For testing only.
     */
    public function setGitRunner(callable $runner): void
    {
        $this->gitRunner = $runner;
    }

    private function runGit(array $command, string $cwd): string
    {
        if ($this->gitRunner !== null) {
            return ($this->gitRunner)($command, $cwd);
        }

        $process = new Process($command);
        $process->setWorkingDirectory($cwd);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }

    private function commitAndPush(string $repoPath, string $commitMessage, string $pushUrl, string $branch): bool
    {
        $status = $this->runGit(['git', 'status', '--porcelain'], $repoPath);

        if (trim($status) === '') {
            return false; // no changes, skip
        }

        $this->runGit(['git', 'add', '-A'], $repoPath);
        $this->runGit(['git', 'commit', '-m', $commitMessage], $repoPath);
        $this->runGit(['git', 'push', $pushUrl, $branch], $repoPath);

        return true;
    }

    public function handle(): void
    {
        $issueService = app(IssueService::class);
        $githubService = app(GitHubService::class);
        $slackService = app(SlackService::class);

        $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();
        $sddRepo = config('sdd.github.sdd_repo');
        $targetRepos = config('sdd.github.target_repos');
        $token = config('sdd.github.token');
        $owner = config('sdd.github.owner');
        $workspacePath = config('sdd.workspace_path');
        $commitMessage = "feat: issue #{$this->issueNumber} {$issue->title}";

        if (!$issue->feature_branch) {
            Log::error("Issue #{$this->issueNumber} has no feature branch set, cannot create PRs.");
            return;
        }

        $prLinks = [];

        foreach ($targetRepos as $repo) {
            try {
                $repoPath = "{$workspacePath}/issue-{$this->issueNumber}/{$repo}";
                $pushUrl = "https://{$token}@github.com/{$owner}/{$repo}.git";

                $hasChanges = $this->commitAndPush($repoPath, $commitMessage, $pushUrl, $issue->feature_branch);

                if (!$hasChanges) {
                    Log::info("No changes in {$repo} for issue #{$this->issueNumber}, skipping PR.");
                    continue;
                }

                $pr = $githubService->createPullRequest(
                    $repo,
                    $issue->feature_branch,
                    'develop',
                    "[SDD #{$this->issueNumber}] {$issue->title}",
                    "Auto-generated PR for SDD issue #{$this->issueNumber}\n\n{$issue->body}"
                );
                $prLinks[] = "[{$repo} PR #{$pr['number']}]({$pr['html_url']})";
            } catch (\Exception $e) {
                $safeMessage = str_replace($token, '***', $e->getMessage());
                Log::warning("Failed to create PR for {$repo}: {$safeMessage}");
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
