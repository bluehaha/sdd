<?php

namespace Tests\Feature\Services;

use App\Services\GitHubService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubServiceTest extends TestCase
{
    private GitHubService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sdd.github.token' => 'test-token']);
        config(['sdd.github.owner' => 'Waltily-Inc']);
        $this->service = new GitHubService();
    }

    public function test_get_issue(): void
    {
        Http::fake([
            'api.github.com/repos/Waltily-Inc/sdd/issues/42' => Http::response([
                'number' => 42,
                'title' => 'Add login feature',
                'body' => 'As a user I want to login',
                'user' => ['login' => 'pm-user'],
            ]),
        ]);

        $issue = $this->service->getIssue('sdd', 42);
        $this->assertEquals(42, $issue['number']);
        $this->assertEquals('Add login feature', $issue['title']);
    }

    public function test_post_comment(): void
    {
        Http::fake([
            'api.github.com/repos/Waltily-Inc/sdd/issues/42/comments' => Http::response(['id' => 1], 201),
        ]);

        $result = $this->service->postComment('sdd', 42, 'Spec looks good!');
        $this->assertEquals(1, $result['id']);
        Http::assertSent(function ($request) {
            return $request['body'] === 'Spec looks good!';
        });
    }

    public function test_add_label(): void
    {
        Http::fake([
            'api.github.com/repos/Waltily-Inc/sdd/issues/42/labels' => Http::response([['name' => 'spec_pass']]),
        ]);

        $this->service->addLabel('sdd', 42, 'spec_pass');
        Http::assertSent(function ($request) {
            return in_array('spec_pass', $request['labels']);
        });
    }

    public function test_remove_label(): void
    {
        Http::fake([
            'api.github.com/repos/Waltily-Inc/sdd/issues/42/labels/spec_ready' => Http::response([], 200),
        ]);

        $this->service->removeLabel('sdd', 42, 'spec_ready');
        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE';
        });
    }

    public function test_create_pull_request(): void
    {
        Http::fake([
            'api.github.com/repos/Waltily-Inc/waltily/pulls' => Http::response([
                'number' => 99,
                'html_url' => 'https://github.com/Waltily-Inc/waltily/pull/99',
            ], 201),
        ]);

        $pr = $this->service->createPullRequest('waltily', 'feat/issue-42', 'develop', 'Add login feature', 'Implements #42');
        $this->assertEquals(99, $pr['number']);
    }
}
