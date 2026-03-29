<?php

namespace Tests\Unit\Services;

use App\Models\PmMapping;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sdd.slack.bot_token' => 'xoxb-test-token']);
        $this->service = new SlackService();
    }

    public function test_notify_spec_passed(): void
    {
        PmMapping::create([
            'github_username' => 'pm-user',
            'slack_user_id' => 'U12345',
        ]);

        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        $this->service->notifyPm('pm-user', 'Spec for issue #42 passed validation. Development starting.');

        Http::assertSent(function ($request) {
            return $request['channel'] === 'U12345'
                && str_contains($request['text'], 'issue #42 passed validation');
        });
    }

    public function test_notify_unknown_user_does_not_throw(): void
    {
        Http::fake();
        $this->service->notifyPm('unknown-user', 'Test message');
        Http::assertNothingSent();
    }
}
