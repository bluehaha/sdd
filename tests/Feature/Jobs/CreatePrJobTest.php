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

        config(['sdd.github.target_repos' => ['waltily', 'waltily-frontend']]);
        config(['sdd.github.sdd_repo' => 'sdd']);

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
        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::Approved, $issue->status);
    }
}
