<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\CleanupJob;
use App\Models\Issue;
use App\Models\PreviewEnvironment;
use App\Services\DbCloneService;
use App\Services\PreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CleanupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_preview_and_db(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Approved->value,
        ]);

        PreviewEnvironment::create([
            'issue_id' => $issue->id,
            'subdomain' => 'issue-42.dev.waltily.tw',
            'workspace_path' => '/var/www/sdd/workspaces/issue-42',
            'cloned_db_name' => 'waltily_issue_42',
        ]);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('teardown')->once()->with(42);
        $this->app->instance(PreviewService::class, $previewService);

        $dbCloneService = Mockery::mock(DbCloneService::class);
        $dbCloneService->shouldReceive('dropDatabase')->once()->with('waltily_issue_42');
        $this->app->instance(DbCloneService::class, $dbCloneService);

        $job = new CleanupJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Done, $issue->status);
    }

    public function test_cleanup_without_db_clone(): void
    {
        $issue = Issue::create([
            'issue_number' => 43,
            'title' => 'Fix button',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Approved->value,
        ]);

        PreviewEnvironment::create([
            'issue_id' => $issue->id,
            'subdomain' => 'issue-43.dev.waltily.tw',
            'workspace_path' => '/var/www/sdd/workspaces/issue-43',
            'cloned_db_name' => null,
        ]);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('teardown')->once()->with(43);
        $this->app->instance(PreviewService::class, $previewService);

        $dbCloneService = Mockery::mock(DbCloneService::class);
        $dbCloneService->shouldNotReceive('dropDatabase');
        $this->app->instance(DbCloneService::class, $dbCloneService);

        $job = new CleanupJob(43);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Done, $issue->status);
    }
}
