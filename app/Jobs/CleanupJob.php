<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Services\DbCloneService;
use App\Services\IssueService;
use App\Services\PreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $issueNumber
    ) {
        $this->onQueue('sdd');
    }

    public function handle(): void
    {
        $issueService = app(IssueService::class);
        $previewService = app(PreviewService::class);
        $dbCloneService = app(DbCloneService::class);

        $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();
        $preview = $issue->previewEnvironment;

        if ($preview) {
            if ($preview->cloned_db_name) {
                $dbCloneService->dropDatabase($preview->cloned_db_name);
            }

            $previewService->teardown($this->issueNumber);
            $preview->delete();
        }

        $issueService->transitionTo($issue, IssueStatus::Done);
    }
}
