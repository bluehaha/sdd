<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Jobs\CleanupJob;
use App\Jobs\CreatePrJob;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\ValidateSpecJob;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function webhookHeaders(string $payload): array
    {
        $secret = config('sdd.github.webhook_secret');
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return [
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Event' => 'issues',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sdd.github.webhook_secret' => 'test-secret']);
        Queue::fake();
    }

    public function test_spec_ready_label_dispatches_validate_spec_job(): void
    {
        $payload = json_encode([
            'action' => 'labeled',
            'label' => ['name' => 'spec_ready'],
            'issue' => [
                'number' => 42,
                'title' => 'Add login',
                'body' => 'Spec body',
                'user' => ['login' => 'pm-user'],
            ],
        ]);

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            $this->webhookHeaders($payload)
        )->assertOk();

        Queue::assertPushed(ValidateSpecJob::class, function ($job) {
            return $job->issueNumber === 42;
        });
    }

    public function test_spec_pass_label_dispatches_execute_task_job(): void
    {
        Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::SpecPassed->value,
        ]);

        $payload = json_encode([
            'action' => 'labeled',
            'label' => ['name' => 'spec_pass'],
            'issue' => [
                'number' => 42,
                'title' => 'Add login',
                'body' => 'body',
                'user' => ['login' => 'pm-user'],
            ],
        ]);

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            $this->webhookHeaders($payload)
        )->assertOk();

        Queue::assertPushed(ExecuteTaskJob::class, function ($job) {
            return $job->issueNumber === 42;
        });
    }

    public function test_approved_label_dispatches_create_pr_job(): void
    {
        Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-42',
        ]);

        $payload = json_encode([
            'action' => 'labeled',
            'label' => ['name' => 'approved'],
            'issue' => [
                'number' => 42,
                'title' => 'Add login',
                'body' => 'body',
                'user' => ['login' => 'pm-user'],
            ],
        ]);

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            $this->webhookHeaders($payload)
        )->assertOk();

        Queue::assertPushed(CreatePrJob::class, function ($job) {
            return $job->issueNumber === 42;
        });
    }

    public function test_issue_comment_dispatches_execute_task_with_resume(): void
    {
        Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::PreviewReady->value,
            'dev_session_id' => 'sess-dev-123',
        ]);

        $payload = json_encode([
            'action' => 'created',
            'issue' => [
                'number' => 42,
                'title' => 'Add login',
                'body' => 'body',
                'user' => ['login' => 'pm-user'],
            ],
            'comment' => [
                'body' => 'Please fix the button color',
                'user' => ['login' => 'pm-user'],
            ],
        ]);

        $headers = $this->webhookHeaders($payload);
        $headers['X-GitHub-Event'] = 'issue_comment';

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            $headers
        )->assertOk();

        Queue::assertPushed(ExecuteTaskJob::class, function ($job) {
            return $job->issueNumber === 42
                && $job->feedbackComment === 'Please fix the button color';
        });
    }

    public function test_issue_closed_dispatches_cleanup_job(): void
    {
        Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Done->value,
        ]);

        $payload = json_encode([
            'action' => 'closed',
            'issue' => [
                'number' => 42,
                'title' => 'Add login',
                'body' => 'body',
                'user' => ['login' => 'pm-user'],
            ],
        ]);

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            $this->webhookHeaders($payload)
        )->assertOk();

        Queue::assertPushed(CleanupJob::class, function ($job) {
            return $job->issueNumber === 42;
        });
    }

    public function test_invalid_signature_returns_403(): void
    {
        $payload = json_encode(['action' => 'labeled']);

        $this->postJson(
            '/api/webhook/github',
            json_decode($payload, true),
            [
                'X-Hub-Signature-256' => 'sha256=invalid',
                'X-GitHub-Event' => 'issues',
            ]
        )->assertForbidden();
    }
}
