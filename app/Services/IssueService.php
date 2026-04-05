<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Issue;

class IssueService
{
    public function findOrCreateIssue(
        int $issueNumber,
        string $title,
        ?string $body,
        string $githubAuthor
    ): Issue {
        return Issue::firstOrCreate(
            ['issue_number' => $issueNumber],
            [
                'title' => $title,
                'body' => $body,
                'github_author' => $githubAuthor,
                'status' => IssueStatus::Pending->value,
                'feature_branch' => "feature/issue-{$issueNumber}",
            ]
        );
    }

    public function transitionTo(Issue $issue, IssueStatus $status): void
    {
        $issue->update(['status' => $status->value]);
    }

    public function saveSpecSessionId(Issue $issue, string $sessionId): void
    {
        $issue->update(['spec_session_id' => $sessionId]);
    }

    public function saveDevSessionId(Issue $issue, string $sessionId): void
    {
        $issue->update(['dev_session_id' => $sessionId]);
    }
}
