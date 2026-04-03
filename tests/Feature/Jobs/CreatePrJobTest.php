<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\CreatePrJob;
use App\Models\Issue;
use App\Services\GitHubService;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CreatePrJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sdd.github.target_repos' => ['waltily', 'waltily-frontend']]);
        config(['sdd.github.sdd_repo' => 'sdd']);
        config(['sdd.github.token' => 'test-token']);
        config(['sdd.github.owner' => 'Waltily-Inc']);
        config(['sdd.workspace_path' => '/tmp/workspaces']);
    }

    public function test_creates_pr_on_target_repos(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-42',
        ]);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily', 'feat/issue-42', 'develop', Mockery::type('string'), Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 99, 'html_url' => 'https://github.com/Waltily-Inc/waltily/pull/99']);
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily-frontend', 'feat/issue-42', 'develop', Mockery::type('string'), Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 50, 'html_url' => 'https://github.com/Waltily-Inc/waltily-frontend/pull/50']);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(42);
        $job->setGitRunner(function (array $command, string $cwd) {
            if ($command === ['git', 'status', '--porcelain']) {
                return ' M src/foo.php'; // has changes
            }
            return ''; // add, commit, push succeed
        });

        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::Approved, $issue->status);
    }

    public function test_skips_repo_with_no_changes(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 43,
            'title' => 'Add logout',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-43',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);
        config(['sdd.github.sdd_repo' => 'sdd']);
        config(['sdd.github.token' => 'test-token']);
        config(['sdd.github.owner' => 'Waltily-Inc']);
        config(['sdd.workspace_path' => '/tmp/workspaces']);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldNotReceive('createPullRequest');
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(43);
        $job->setGitRunner(function (array $command, string $cwd) {
            if ($command === ['git', 'status', '--porcelain']) {
                return ''; // no changes
            }
            throw new \RuntimeException('Unexpected git command: ' . implode(' ', $command));
        });

        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::Approved, $issue->status);
    }

    public function test_fails_job_when_commits_made_but_all_prs_fail(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 45,
            'title' => 'Add notifications',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-45',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('createPullRequest')
            ->andThrow(new \Exception('GitHub API error'));
        $githubService->shouldNotReceive('postComment');
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldNotReceive('notifyPm');
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(45);
        $job->setGitRunner(function (array $command, string $cwd) {
            if ($command === ['git', 'status', '--porcelain']) {
                return ' M src/foo.php'; // has changes
            }
            return ''; // add, commit, push succeed
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Issue #45.*all PRs failed/i');

        $job->handle();

        // Issue should NOT be transitioned to Approved
        $issue->refresh();
        $this->assertNotEquals(IssueStatus::Approved, $issue->status);
    }

    public function test_commits_and_pushes_before_creating_pr(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 44,
            'title' => 'Add dashboard',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-44',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);
        config(['sdd.github.sdd_repo' => 'sdd']);
        config(['sdd.github.token' => 'test-token']);
        config(['sdd.github.owner' => 'Waltily-Inc']);
        config(['sdd.workspace_path' => '/tmp/workspaces']);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily', 'feat/issue-44', 'develop', '[SDD #44] Add dashboard', Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 101, 'html_url' => 'https://github.com/Waltily-Inc/waltily/pull/101']);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $executedCommands = [];
        $job = new CreatePrJob(44);
        $job->setGitRunner(function (array $command, string $cwd) use (&$executedCommands) {
            $executedCommands[] = ['command' => $command, 'cwd' => $cwd];
            if ($command === ['git', 'status', '--porcelain']) {
                return ' M src/foo.php'; // has changes
            }
            return ''; // git add, commit, push succeed
        });

        $job->handle();

        $commands = array_column($executedCommands, 'command');
        $this->assertSame(['git', 'status', '--porcelain'], $commands[0]);
        $this->assertSame(['git', 'add', '-A'], $commands[1]);
        $this->assertSame(['git', 'commit', '-m', 'feat: issue #44 Add dashboard'], $commands[2]);
        $this->assertSame(
            ['git', 'push', 'https://test-token@github.com/Waltily-Inc/waltily.git', 'feat/issue-44'],
            $commands[3]
        );

        foreach ($executedCommands as $call) {
            $this->assertEquals('/tmp/workspaces/issue-44/waltily', $call['cwd']);
        }
    }
}
