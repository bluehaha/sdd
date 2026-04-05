<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Jobs\CleanupJob;
use App\Jobs\CreatePrJob;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\SetupPreviewJob;
use App\Jobs\ValidateSpecJob;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sdd.github.webhook_secret' => 'test-secret']);
        Queue::fake();
    }

    private function sendWebhook(string $event, array $payload): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $json, 'test-secret');

        return $this->postJson('/api/webhook/github', $payload, [
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Event' => $event,
        ]);
    }

    public function test_full_lifecycle_spec_ready_to_close(): void
    {
        // Step 1: PM adds spec_ready label
        $this->sendWebhook('issues', [
            'action' => 'labeled',
            'label' => ['name' => 'spec_ready'],
            'issue' => [
                'number' => 1,
                'title' => 'Add user profile page',
                'body' => 'As a user I want to see my profile',
                'user' => ['login' => 'pm-alice'],
            ],
        ])->assertOk();

        Queue::assertPushed(ValidateSpecJob::class);
        $this->assertDatabaseHas('issues', [
            'issue_number' => 1,
            'github_author' => 'pm-alice',
        ]);

        // Step 2: Spec passes → spec_pass label added
        $this->sendWebhook('issues', [
            'action' => 'labeled',
            'label' => ['name' => 'spec_pass'],
            'issue' => [
                'number' => 1,
                'title' => 'Add user profile page',
                'body' => 'Validated spec',
                'user' => ['login' => 'pm-alice'],
            ],
        ])->assertOk();

        Queue::assertPushed(ExecuteTaskJob::class, function ($job) {
            return $job->issueNumber === 1 && $job->feedbackComment === null;
        });

        // Step 3: PM adds feedback comment
        $issue = Issue::where('issue_number', 1)->first();
        $issue->update([
            'status' => IssueStatus::PreviewReady->value,
            'dev_session_id' => 'dev-sess-1',
        ]);

        $this->sendWebhook('issue_comment', [
            'action' => 'created',
            'issue' => [
                'number' => 1,
                'title' => 'Add user profile page',
                'body' => 'body',
                'user' => ['login' => 'pm-alice'],
            ],
            'comment' => [
                'body' => 'Change header color to blue',
                'user' => ['login' => 'pm-alice'],
            ],
        ])->assertOk();

        Queue::assertPushed(ExecuteTaskJob::class, function ($job) {
            return $job->issueNumber === 1
                && $job->feedbackComment === 'Change header color to blue';
        });

        // Step 4: PM approves
        $issue->update([
            'status' => IssueStatus::PreviewReady->value,
            'feature_branch' => 'feat/issue-1',
        ]);

        $this->sendWebhook('issues', [
            'action' => 'labeled',
            'label' => ['name' => 'approved'],
            'issue' => [
                'number' => 1,
                'title' => 'Add user profile page',
                'body' => 'body',
                'user' => ['login' => 'pm-alice'],
            ],
        ])->assertOk();

        Queue::assertPushed(CreatePrJob::class);

        // Step 5: Issue closed
        $this->sendWebhook('issues', [
            'action' => 'closed',
            'issue' => [
                'number' => 1,
                'title' => 'Add user profile page',
                'body' => 'body',
                'user' => ['login' => 'pm-alice'],
            ],
        ])->assertOk();

        Queue::assertPushed(CleanupJob::class);
    }
}
