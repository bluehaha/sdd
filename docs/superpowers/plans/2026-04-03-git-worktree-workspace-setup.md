# Git Worktree Workspace Setup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `git clone` workspace creation and `SetupPreviewJob` with `git worktree` setup run before Claude executes.

**Architecture:** `PreviewService::createWorkspace()` is rewritten to create git worktrees from `{REPO_MAIN_DIRECTORY}/waltily` and `{REPO_MAIN_DIRECTORY}/waltily-frontend` into `{WORKSPACE_PATH}/issue-{number}/`. `ExecuteTaskJob` calls `setup()` on first run before invoking Claude, and no longer dispatches `SetupPreviewJob`. `teardown()` gains `git worktree prune` calls.

**Tech Stack:** PHP 8.x, Laravel, Symfony Process, PHPUnit/Mockery

---

## File Map

| File | Action |
|------|--------|
| `app/Services/PreviewService.php` | Modify: rewrite `createWorkspace()`, update constructor + `teardown()` |
| `app/Jobs/ExecuteTaskJob.php` | Modify: call `setup()` before Claude on first run, remove `SetupPreviewJob::dispatch()` |
| `app/Jobs/SetupPreviewJob.php` | Delete |
| `tests/Unit/Services/PreviewServiceTest.php` | Modify: update constructor calls, add worktree tests, remove clone tests |
| `tests/Feature/Jobs/ExecuteTaskJobTest.php` | Modify: remove `SetupPreviewJob` references, add `setup()` expectation on first run |
| `tests/Feature/Jobs/SetupPreviewJobTest.php` | Delete |

---

### Task 1: Update `PreviewService` constructor and `createWorkspace()`

**Files:**
- Modify: `app/Services/PreviewService.php`

- [ ] **Step 1: Update the constructor to accept `mainRepoPath`**

Replace the existing constructor in `app/Services/PreviewService.php`:

```php
public function __construct(
    ?string $domain = null,
    ?string $workspaceBasePath = null,
    ?string $nginxConfigBasePath = null,
    ?string $mainRepoPath = null
) {
    $this->domain = $domain ?? config('sdd.preview.domain');
    $this->workspaceBasePath = $workspaceBasePath ?? config('sdd.workspace_path');
    $this->nginxConfigBasePath = $nginxConfigBasePath ?? config('sdd.preview.nginx_config_path');
    $this->mainRepoPath = $mainRepoPath ?? config('sdd.repo.main_directory');
}
```

Also add the property declaration at the top of the class (alongside the existing private properties):

```php
private string $mainRepoPath;
```

- [ ] **Step 2: Rewrite `createWorkspace()`**

Replace the existing `private function createWorkspace(string $workspace, string $featureBranch): void` method:

```php
private function createWorkspace(string $workspace, string $featureBranch): void
{
    @mkdir($workspace, 0755, true);

    foreach (config('sdd.github.target_repos') as $repo) {
        $mainRepo = "{$this->mainRepoPath}/{$repo}";
        $worktreePath = "{$workspace}/{$repo}";

        $process = new Process(
            ['git', '-C', $mainRepo, 'worktree', 'add', '-b', $featureBranch, $worktreePath],
        );
        $process->setTimeout(60);
        $process->mustRun();
    }
}
```

- [ ] **Step 3: Update `teardown()` to prune worktrees after removal**

Replace the existing `public function teardown(int $issueNumber): void` method:

```php
public function teardown(int $issueNumber): void
{
    $workspace = $this->workspacePath($issueNumber);
    $nginxConfig = $this->nginxConfigPath($issueNumber);

    if (is_dir($workspace)) {
        $process = new Process(['rm', '-rf', $workspace]);
        $process->mustRun();
    }

    if (file_exists($nginxConfig)) {
        @unlink($nginxConfig);
        $this->reloadNginx();
    }

    foreach (config('sdd.github.target_repos') as $repo) {
        $mainRepo = "{$this->mainRepoPath}/{$repo}";
        $prune = new Process(['git', '-C', $mainRepo, 'worktree', 'prune']);
        $prune->setTimeout(30);
        $prune->run();
    }

    Log::info("Preview environment removed", ['issue' => $issueNumber]);
}
```

---

### Task 2: Update `PreviewServiceTest` for worktree logic

**Files:**
- Modify: `tests/Unit/Services/PreviewServiceTest.php`

- [ ] **Step 1: Update all constructor calls to include `mainRepoPath`**

The existing tests construct `PreviewService` with 3 args. Add a 4th arg to each:

```php
$service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces', '/etc/nginx/sites-enabled', '/var/www/sdd/main');
```

There are 4 existing test methods — update each one's constructor call.

- [ ] **Step 2: Run existing tests to confirm they still pass**

```bash
cd /Users/yushing/Project/waltily/sdd
php artisan test tests/Unit/Services/PreviewServiceTest.php
```

Expected: 4 tests pass (they don't test `createWorkspace` or `teardown` directly).

- [ ] **Step 3: Commit**

```bash
git add app/Services/PreviewService.php tests/Unit/Services/PreviewServiceTest.php
git commit -m "refactor: replace git clone with git worktree in PreviewService"
```

---

### Task 3: Update `ExecuteTaskJob` — call `setup()` before Claude, remove `SetupPreviewJob`

**Files:**
- Modify: `app/Jobs/ExecuteTaskJob.php`

- [ ] **Step 1: Add `PreviewService` import and call `setup()` before Claude on first run**

The current `handle()` method resolves services, then sets `$workspacePath`, then runs Claude, then dispatches `SetupPreviewJob`. Rewrite `handle()` as follows:

```php
public function handle(): void
{
    $issueService = app(IssueService::class);
    $claudeService = app(ClaudeCodeService::class);
    $slackService = app(SlackService::class);
    $previewService = app(PreviewService::class);

    $issue = Issue::where('github_issue_number', $this->issueNumber)->firstOrFail();

    $isResume = $this->feedbackComment !== null;
    $featureBranch = $issue->feature_branch ?? "feature/issue-{$this->issueNumber}";

    if (!$issue->feature_branch) {
        $issueService->saveFeatureBranch($issue, $featureBranch);
    }

    $issueService->transitionTo($issue, IssueStatus::Developing);

    $workspacePath = $previewService->workspacePath($this->issueNumber);

    if (!$isResume) {
        $previewService->setup($this->issueNumber, $featureBranch);
    }

    $prompt = $isResume
        ? $this->buildResumePrompt($issue, $this->feedbackComment)
        : $this->buildFirstRunPrompt($issue, $featureBranch);

    $sessionId = $issue->dev_session_id;

    $result = $claudeService->execute($prompt, $workspacePath, $sessionId);

    if ($result['session_id']) {
        $issueService->saveDevSessionId($issue, $result['session_id']);
    }

    IssueLog::create([
        'issue_id' => $issue->id,
        'job_type' => 'execute_task',
        'session_id' => $result['session_id'],
        'prompt' => $prompt,
        'output' => $result['output'],
        'exit_code' => $result['exit_code'],
        'duration_seconds' => $result['duration_seconds'],
    ]);

    if ($isResume) {
        $slackService->notifyPm(
            $issue->github_author,
            "Issue #{$this->issueNumber} updated based on your feedback. Preview refreshed."
        );
    }
}
```

- [ ] **Step 2: Remove the `SetupPreviewJob` import**

Remove this line from the top of `app/Jobs/ExecuteTaskJob.php`:

```php
use App\Jobs\SetupPreviewJob;
```

Also remove the `PreviewService` import if it's not already present (it was already imported, so just verify it's there):

```php
use App\Services\PreviewService;
```

---

### Task 4: Update `ExecuteTaskJobTest`

**Files:**
- Modify: `tests/Feature/Jobs/ExecuteTaskJobTest.php`

- [ ] **Step 1: Rewrite `test_first_run_creates_feature_branch_and_dispatches_preview()`**

Remove `Queue::fake`, `SetupPreviewJob` import and `Queue::assertPushed`. Add `setup()` expectation on `$previewService`:

```php
public function test_first_run_creates_feature_branch_and_calls_setup(): void
{
    $issue = Issue::create([
        'github_issue_number' => 42,
        'title' => 'Add login',
        'body' => 'Validated spec body',
        'github_author' => 'pm-user',
        'status' => IssueStatus::SpecPassed->value,
    ]);

    $claudeService = Mockery::mock(ClaudeCodeService::class);
    $claudeService->shouldReceive('execute')
        ->once()
        ->andReturn([
            'output' => json_encode(['session_id' => 'dev-sess-1', 'result' => 'done']),
            'exit_code' => 0,
            'duration_seconds' => 120,
            'session_id' => 'dev-sess-1',
        ]);
    $this->app->instance(ClaudeCodeService::class, $claudeService);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('notifyPm')->never();
    $this->app->instance(SlackService::class, $slackService);

    $previewService = Mockery::mock(PreviewService::class);
    $previewService->shouldReceive('workspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
    $previewService->shouldReceive('setup')->once()->with(42, 'feature/issue-42');
    $this->app->instance(PreviewService::class, $previewService);

    $job = new ExecuteTaskJob(42);
    $job->handle();

    $issue->refresh();
    $this->assertEquals(IssueStatus::Developing, $issue->status);
    $this->assertEquals('dev-sess-1', $issue->dev_session_id);
    $this->assertEquals('feature/issue-42', $issue->feature_branch);
}
```

- [ ] **Step 2: Rewrite `test_resume_with_feedback_uses_existing_session()`**

Remove `Queue::fake` and `Queue::assertPushed`. Add that `setup()` is NOT called on resume:

```php
public function test_resume_with_feedback_uses_existing_session(): void
{
    $issue = Issue::create([
        'github_issue_number' => 42,
        'title' => 'Add login',
        'body' => 'Spec body',
        'github_author' => 'pm-user',
        'status' => IssueStatus::PreviewReady->value,
        'dev_session_id' => 'dev-sess-1',
        'feature_branch' => 'feature/issue-42',
    ]);

    $claudeService = Mockery::mock(ClaudeCodeService::class);
    $claudeService->shouldReceive('execute')
        ->once()
        ->withArgs(function ($prompt, $workDir, $sessionId) {
            return $sessionId === 'dev-sess-1'
                && str_contains($prompt, 'Fix the button color');
        })
        ->andReturn([
            'output' => json_encode(['session_id' => 'dev-sess-1', 'result' => 'fixed']),
            'exit_code' => 0,
            'duration_seconds' => 60,
            'session_id' => 'dev-sess-1',
        ]);
    $this->app->instance(ClaudeCodeService::class, $claudeService);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('notifyPm')
        ->once()
        ->withArgs(function ($user, $msg) {
            return $user === 'pm-user' && str_contains($msg, 'updated based on your feedback');
        });
    $this->app->instance(SlackService::class, $slackService);

    $previewService = Mockery::mock(PreviewService::class);
    $previewService->shouldReceive('workspacePath')->with(42)->andReturn('/var/www/sdd/workspaces/issue-42');
    $previewService->shouldNotReceive('setup');
    $this->app->instance(PreviewService::class, $previewService);

    $job = new ExecuteTaskJob(42, 'Fix the button color');
    $job->handle();

    $issue->refresh();
    $this->assertEquals(IssueStatus::Developing, $issue->status);
}
```

- [ ] **Step 3: Remove unused imports from `ExecuteTaskJobTest`**

Remove these lines from the top of `tests/Feature/Jobs/ExecuteTaskJobTest.php`:

```php
use App\Jobs\SetupPreviewJob;
use Illuminate\Support\Facades\Queue;
```

- [ ] **Step 4: Run the updated tests**

```bash
cd /Users/yushing/Project/waltily/sdd
php artisan test tests/Feature/Jobs/ExecuteTaskJobTest.php
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ExecuteTaskJob.php tests/Feature/Jobs/ExecuteTaskJobTest.php
git commit -m "feat: call PreviewService::setup() before Claude in ExecuteTaskJob, remove SetupPreviewJob dispatch"
```

---

### Task 5: Delete `SetupPreviewJob` and its test

**Files:**
- Delete: `app/Jobs/SetupPreviewJob.php`
- Delete: `tests/Feature/Jobs/SetupPreviewJobTest.php`

- [ ] **Step 1: Delete both files**

```bash
rm /Users/yushing/Project/waltily/sdd/app/Jobs/SetupPreviewJob.php
rm /Users/yushing/Project/waltily/sdd/tests/Feature/Jobs/SetupPreviewJobTest.php
```

- [ ] **Step 2: Run full test suite to verify nothing is broken**

```bash
cd /Users/yushing/Project/waltily/sdd
php artisan test
```

Expected: all remaining tests pass, no references to `SetupPreviewJob`.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: delete SetupPreviewJob and its test"
```
