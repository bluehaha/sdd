<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\SetupPreviewJob;
use App\Models\Issue;
use App\Services\ClaudeCodeService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ExecuteTaskJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_feature_branch_and_dispatches_preview(): void
    {
        Queue::fake([SetupPreviewJob::class]);

        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Validated spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::SpecPassed->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')
            ->once()
            ->andReturn([
                'output' => json_encode(['session_id' => 'dev-sess-1', 'result' => 'done']),
                'exit_code' => 0,
                'duration_seconds' => 120,
                'session_id' => 'dev-sess-1',
            ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->never();
        $this->app->instance(SlackService::class, $slackService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('workspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(42);
        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::Developing, $issue->status);
        $this->assertEquals('dev-sess-1', $issue->dev_session_id);
        $this->assertEquals('feat/issue-42', $issue->feature_branch);
        Queue::assertPushed(SetupPreviewJob::class);
    }

    public function test_resume_with_feedback_uses_existing_session(): void
    {
        Queue::fake([SetupPreviewJob::class]);

        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'dev_session_id' => 'dev-sess-1',
            'feature_branch' => 'feat/issue-42',
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')
            ->once()
            ->withArgs(function ($prompt, $workDir, $sessionId) {
                return $sessionId === 'dev-sess-1'
                    && str_contains($prompt, 'Fix the button color');
            })
            ->andReturn([
                'output' => json_encode(['session_id' => 'dev-sess-1', 'result' => 'fixed']),
                'exit_code' => 0,
                'duration_seconds' => 60,
                'session_id' => 'dev-sess-1',
            ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')
            ->once()
            ->withArgs(function ($user, $msg) {
                return $user === 'pm-user' && str_contains($msg, 'updated based on your feedback');
            });
        $this->app->instance(SlackService::class, $slackService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('workspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(42, 'Fix the button color');
        $job->handle();

        $issue->refresh();
        $this->assertEquals(IssueStatus::Developing, $issue->status);
    }
}
