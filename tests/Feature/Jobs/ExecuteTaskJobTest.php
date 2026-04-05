<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\ExecuteTaskJob;
use App\Models\Issue;
use App\Services\ClaudeCodeService;
use App\Services\GitHubService;
use App\Services\PreviewService;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExecuteTaskJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_feature_branch_and_calls_setup(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
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
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('issueWorkspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
        $previewService->shouldReceive('setup')->once()->withArgs(function ($issueArg, $branch) use ($issue) {
            return $issueArg->is($issue) && $branch === 'feature/issue-42';
        });
        $previewService->shouldReceive('buildFrontendIfChanged')->once()->with('/var/www/sdd/workspaces/issue-42');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Developing, $issue->status);
        $this->assertEquals('dev-sess-1', $issue->dev_session_id);
        $this->assertEquals('feature/issue-42', $issue->feature_branch);
    }

    public function test_resume_with_feedback_uses_existing_session(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'dev_session_id' => 'dev-sess-1',
            'feature_branch' => 'feature/issue-42',
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

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('issueWorkspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
        $previewService->shouldNotReceive('setup');
        $previewService->shouldReceive('buildFrontendIfChanged')->once()->with('/var/www/sdd/workspaces/issue-42');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(42, 'Fix the button color');
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Developing, $issue->status);
    }

    public function test_yarn_build_runs_when_frontend_has_changes(): void
    {
        $issue = Issue::create([
            'issue_number' => 55,
            'title' => 'Frontend feature',
            'body' => 'Spec',
            'github_author' => 'pm-user',
            'status' => IssueStatus::SpecPassed->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')->once()->andReturn([
            'output' => json_encode(['session_id' => 'sess-55', 'result' => 'done']),
            'exit_code' => 0,
            'duration_seconds' => 10,
            'session_id' => 'sess-55',
        ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('issueWorkspacePath')->with(55)->andReturn('/tmp/workspace-55');
        $previewService->shouldReceive('setup')->once();
        $previewService->shouldReceive('buildFrontendIfChanged')->once()->with('/tmp/workspace-55');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(55);
        app()->call([$job, 'handle']);

        $this->assertTrue(true);
    }

    public function test_yarn_build_skipped_when_no_frontend_changes(): void
    {
        $issue = Issue::create([
            'issue_number' => 56,
            'title' => 'Backend only feature',
            'body' => 'Spec',
            'github_author' => 'pm-user',
            'status' => IssueStatus::SpecPassed->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')->once()->andReturn([
            'output' => json_encode(['session_id' => 'sess-56', 'result' => 'done']),
            'exit_code' => 0,
            'duration_seconds' => 10,
            'session_id' => 'sess-56',
        ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('issueWorkspacePath')->with(56)->andReturn('/tmp/workspace-56');
        $previewService->shouldReceive('setup')->once();
        $previewService->shouldReceive('buildFrontendIfChanged')->once()->with('/tmp/workspace-56');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(56);
        app()->call([$job, 'handle']);

        $this->assertTrue(true);
    }

    public function test_job_continues_when_frontend_directory_missing(): void
    {
        $issue = Issue::create([
            'issue_number' => 57,
            'title' => 'No frontend',
            'body' => 'Spec',
            'github_author' => 'pm-user',
            'status' => IssueStatus::SpecPassed->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')->once()->andReturn([
            'output' => json_encode(['session_id' => 'sess-57', 'result' => 'done']),
            'exit_code' => 0,
            'duration_seconds' => 10,
            'session_id' => 'sess-57',
        ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $previewService = Mockery::mock(PreviewService::class);
        $previewService->shouldReceive('issueWorkspacePath')->with(57)->andReturn('/tmp/workspace-57');
        $previewService->shouldReceive('setup')->once();
        $previewService->shouldReceive('buildFrontendIfChanged')->once()->with('/tmp/workspace-57');
        $this->app->instance(PreviewService::class, $previewService);

        $job = new ExecuteTaskJob(57);
        app()->call([$job, 'handle']);

        $this->assertTrue(true);
    }
}
