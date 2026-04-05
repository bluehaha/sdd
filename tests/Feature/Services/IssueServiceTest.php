<?php

namespace Tests\Feature\Services;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueServiceTest extends TestCase
{
    use RefreshDatabase;

    private IssueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IssueService();
    }

    public function test_find_or_create_issue_creates_new(): void
    {
        $issue = $this->service->findOrCreateIssue(42, 'Add login', 'Spec body', 'pm-user');

        $this->assertDatabaseHas('issues', [
            'issue_number' => 42,
            'title' => 'Add login',
            'status' => IssueStatus::Pending->value,
        ]);
        $this->assertEquals(42, $issue->issue_number);
    }

    public function test_find_or_create_issue_returns_existing(): void
    {
        Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'Spec body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Developing->value,
        ]);

        $issue = $this->service->findOrCreateIssue(42, 'Add login v2', 'New body', 'pm-user');

        $this->assertEquals(IssueStatus::Developing, $issue->status);
        $this->assertDatabaseCount('issues', 1);
    }

    public function test_transition_status(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
            'status' => IssueStatus::Pending->value,
        ]);

        $this->service->transitionTo($issue, IssueStatus::SpecValidating);

        $issue->refresh();
        $this->assertEquals(IssueStatus::SpecValidating, $issue->status);
    }

    public function test_save_spec_session_id(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
        ]);

        $this->service->saveSpecSessionId($issue, 'sess-abc');

        $issue->refresh();
        $this->assertEquals('sess-abc', $issue->spec_session_id);
    }

    public function test_save_dev_session_id(): void
    {
        $issue = Issue::create([
            'issue_number' => 42,
            'title' => 'Add login',
            'body' => 'body',
            'github_author' => 'pm-user',
        ]);

        $this->service->saveDevSessionId($issue, 'sess-dev-xyz');

        $issue->refresh();
        $this->assertEquals('sess-dev-xyz', $issue->dev_session_id);
    }
}
