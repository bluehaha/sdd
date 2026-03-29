<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\SetupPreviewJob;
use App\Models\Issue;
use App\Services\DbCloneService;
use App\Services\GitHubService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SetupPreviewJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_preview_without_db_clone(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Developing->value,
            'feature_branch' => 'feat/issue-42',
        ]);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('workspacePath')->with(42)->andReturn('/tmp/nonexistent-workspace/issue-42');
        $previewService->shouldReceive('setup')->once()->with(42, 'feat/issue-42', null)->andReturn('issue-42.dev.waltily.tw');
        $this->app->instance(PreviewService::class, $previewService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('addLabel')->once()->with('sdd', 42, 'feature_done');
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $dbCloneService = Mockery::mock(DbCloneService::class);
        $this->app->instance(DbCloneService::class, $dbCloneService);

        config(['sdd.github.sdd_repo' => 'sdd']);

        $job = new SetupPreviewJob(42);
        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::PreviewReady, $issue->status);
        $this->assertDatabaseHas('preview_environments', [
            'issue_id' => $issue->id,
            'subdomain' => 'issue-42.dev.waltily.tw',
        ]);
    }
}
