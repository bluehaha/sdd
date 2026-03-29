<?php

namespace App\Services;

use App\Models\PmMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    private string $botToken;

    public function __construct()
    {
        $this->botToken = config('sdd.slack.bot_token');
    }

    public function notifyPm(string $githubUsername, string $message): void
    {
        $mapping = PmMapping::where('github_username', $githubUsername)->first();

        if (!$mapping) {
            Log::warning("No Slack mapping found for GitHub user: {$githubUsername}");
            return;
        }

        Http::withToken($this->botToken)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $mapping->slack_user_id,
                'text' => $message,
            ]);
    }
}
