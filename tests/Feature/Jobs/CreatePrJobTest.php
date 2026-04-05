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
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-42',
        ]);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('hasChanges')->andReturn(true);
        $githubService->shouldReceive('stageAll')->twice();
        $githubService->shouldReceive('commit')->twice();
        $githubService->shouldReceive('push')->twice();
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily', 'feat/issue-42', 'main', Mockery::type('string'), Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 99, 'html_url' => 'https://github.com/Waltily-Inc/waltily/pull/99']);
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily-frontend', 'feat/issue-42', 'main', Mockery::type('string'), Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 50, 'html_url' => 'https://github.com/Waltily-Inc/waltily-frontend/pull/50']);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Approved, $issue->status);
    }

    public function test_skips_repo_with_no_changes(): void
    {
        $issue = Issue::create([
            'issue_number' => 43,
            'title' => 'Add logout',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-43',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('hasChanges')->andReturn(false);
        $githubService->shouldNotReceive('stageAll');
        $githubService->shouldNotReceive('commit');
        $githubService->shouldNotReceive('push');
        $githubService->shouldNotReceive('createPullRequest');
        $githubService->shouldNotReceive('postComment');
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldNotReceive('notifyPm');
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(43);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Issue #43.*all PRs failed/i');

        app()->call([$job, 'handle']);
    }

    public function test_fails_job_when_commits_made_but_all_prs_fail(): void
    {
        $issue = Issue::create([
            'issue_number' => 45,
            'title' => 'Add notifications',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-45',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('hasChanges')->andReturn(true);
        $githubService->shouldReceive('stageAll')->once();
        $githubService->shouldReceive('commit')->once();
        $githubService->shouldReceive('push')->once();
        $githubService->shouldReceive('createPullRequest')
            ->andThrow(new \Exception('GitHub API error'));
        $githubService->shouldNotReceive('postComment');
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldNotReceive('notifyPm');
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(45);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Issue #45.*all PRs failed/i');

        app()->call([$job, 'handle']);
    }

    public function test_commits_and_pushes_before_creating_pr(): void
    {
        $issue = Issue::create([
            'issue_number' => 44,
            'title' => 'Add dashboard',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-44',
        ]);

        config(['sdd.github.target_repos' => ['waltily']]);

        $repoPath = '/tmp/workspaces/issue-44/waltily';

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('hasChanges')
            ->with($repoPath)->once()->andReturn(true);
        $githubService->shouldReceive('stageAll')
            ->with($repoPath)->once();
        $githubService->shouldReceive('commit')
            ->with($repoPath, 'feat: issue #44 Add dashboard')->once();
        $githubService->shouldReceive('push')
            ->with('waltily', $repoPath, 'feat/issue-44')->once();
        $githubService->shouldReceive('createPullRequest')
            ->with('waltily', 'feat/issue-44', 'main', '[SDD #44] Add dashboard', Mockery::type('string'))
            ->once()
            ->andReturn(['number' => 101, 'html_url' => 'https://github.com/Waltily-Inc/waltily/pull/101']);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $job = new CreatePrJob(44);
        app()->call([$job, 'handle']);
    }
}
