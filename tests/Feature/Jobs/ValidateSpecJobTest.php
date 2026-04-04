<?php

namespace Tests\Feature\Jobs;

use App\Enums\IssueStatus;
use App\Jobs\ValidateSpecJob;
use App\Models\Issue;
use App\Services\ClaudeCodeService;
use App\Services\GitHubService;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ValidateSpecJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_spec_passes_validation(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Clear spec with acceptance criteria',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Pending->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')
            ->once()
            ->andReturn([
                'output' => json_encode([
                    'session_id' => 'spec-sess-1',
                    'result' => json_encode(['passed' => true, 'summary' => 'Spec is clear']),
                ]),
                'exit_code' => 0,
                'duration_seconds' => 30,
                'session_id' => 'spec-sess-1',
            ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('removeLabel')->once()->with('sdd', 42, 'spec_ready');
        $githubService->shouldReceive('addLabel')->once()->with('sdd', 42, 'spec_pass');
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        config(['sdd.github.sdd_repo' => 'sdd']);

        $job = new ValidateSpecJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::SpecPassed, $issue->status);
        $this->assertEquals('spec-sess-1', $issue->spec_session_id);
    }

    public function test_spec_fails_validation(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Vague spec',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Pending->value,
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')
            ->once()
            ->andReturn([
                'output' => json_encode([
                    'session_id' => 'spec-sess-1',
                    'result' => json_encode(['passed' => false, 'feedback' => 'Missing acceptance criteria']),
                ]),
                'exit_code' => 0,
                'duration_seconds' => 20,
                'session_id' => 'spec-sess-1',
            ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('postComment')->once()->with('sdd', 42, Mockery::type('string'));
        $githubService->shouldReceive('removeLabel')->once()->with('sdd', 42, 'spec_ready');
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        config(['sdd.github.sdd_repo' => 'sdd']);

        $job = new ValidateSpecJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::Pending, $issue->status);
        $this->assertEquals('spec-sess-1', $issue->spec_session_id);
    }

    public function test_resume_uses_existing_session(): void
    {
        $issue = Issue::create([
            'github_issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Updated spec',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Pending->value,
            'spec_session_id' => 'spec-sess-1',
        ]);

        $claudeService = Mockery::mock(ClaudeCodeService::class);
        $claudeService->shouldReceive('execute')
            ->once()
            ->withArgs(function ($prompt, $workDir, $sessionId) {
                return $sessionId === 'spec-sess-1';
            })
            ->andReturn([
                'output' => json_encode([
                    'session_id' => 'spec-sess-1',
                    'result' => json_encode(['passed' => true, 'summary' => 'Now clear']),
                ]),
                'exit_code' => 0,
                'duration_seconds' => 15,
                'session_id' => 'spec-sess-1',
            ]);
        $this->app->instance(ClaudeCodeService::class, $claudeService);

        $githubService = Mockery::mock(GitHubService::class);
        $githubService->shouldReceive('removeLabel')->once()->with('sdd', 42, 'spec_ready');
        $githubService->shouldReceive('addLabel')->once();
        $githubService->shouldReceive('postComment')->once();
        $this->app->instance(GitHubService::class, $githubService);

        $slackService = Mockery::mock(SlackService::class);
        $slackService->shouldReceive('notifyPm')->once();
        $this->app->instance(SlackService::class, $slackService);

        config(['sdd.github.sdd_repo' => 'sdd']);

        $job = new ValidateSpecJob(42);
        app()->call([$job, 'handle']);

        $issue->refresh();
        $this->assertEquals(IssueStatus::SpecPassed, $issue->status);
    }
}
