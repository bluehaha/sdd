<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Jobs\CleanupJob;
use App\Jobs\CreatePrJob;
use App\Jobs\ExecuteTaskJob;
use App\Jobs\ValidateSpecJob;
use App\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private IssueService $issueService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();

        return match ($event) {
            'issues' => $this->handleIssueEvent($payload),
            'issue_comment' => $this->handleCommentEvent($payload),
            default => response()->json(['status' => 'ignored']),
        };
    }

    private function handleIssueEvent(array $payload): JsonResponse
    {
        $action = $payload['action'];
        $issueData = $payload['issue'];
        $issueNumber = $issueData['number'];

        if ($action === 'labeled') {
            $label = $payload['label']['name'];
            $this->issueService->findOrCreateIssue(
                $issueNumber,
                $issueData['title'],
                $issueData['body'] ?? null,
                $issueData['user']['login']
            );

            return match ($label) {
                'spec_ready' => $this->dispatchValidateSpec($issueNumber),
                'spec_pass' => $this->dispatchExecuteTask($issueNumber),
                'approved' => $this->dispatchCreatePr($issueNumber),
                default => response()->json(['status' => 'label_ignored']),
            };
        }

        if ($action === 'closed') {
            CleanupJob::dispatch($issueNumber);
            return response()->json(['status' => 'cleanup_dispatched']);
        }

        return response()->json(['status' => 'ignored']);
    }

    private function handleCommentEvent(array $payload): JsonResponse
    {
        if ($payload['action'] !== 'created') {
            return response()->json(['status' => 'ignored']);
        }

        $issueNumber = $payload['issue']['number'];
        $commentBody = $payload['comment']['body'];

        $issue = $this->issueService->findOrCreateIssue(
            $issueNumber,
            $payload['issue']['title'],
            $payload['issue']['body'] ?? null,
            $payload['issue']['user']['login']
        );

        $resumableStatuses = [IssueStatus::Developing, IssueStatus::PreviewReady];

        if (in_array($issue->status, $resumableStatuses)) {
            ExecuteTaskJob::dispatch($issueNumber, $commentBody);
            return response()->json(['status' => 'resume_dispatched']);
        }

        return response()->json(['status' => 'ignored']);
    }

    private function dispatchValidateSpec(int $issueNumber): JsonResponse
    {
        ValidateSpecJob::dispatch($issueNumber);
        return response()->json(['status' => 'validate_spec_dispatched']);
    }

    private function dispatchExecuteTask(int $issueNumber): JsonResponse
    {
        ExecuteTaskJob::dispatch($issueNumber);
        return response()->json(['status' => 'execute_task_dispatched']);
    }

    private function dispatchCreatePr(int $issueNumber): JsonResponse
    {
        CreatePrJob::dispatch($issueNumber);
        return response()->json(['status' => 'create_pr_dispatched']);
    }
}
