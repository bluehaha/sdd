# Git Worktree Workspace Setup Design

**Date:** 2026-04-03

## Overview

Replace the existing `git clone` workspace setup (currently in `SetupPreviewJob` / `PreviewService::createWorkspace()`) with `git worktree` creation from local main repos. Worktrees are created before Claude runs, not after. `SetupPreviewJob` is removed entirely.

## Goals

- Create issue workspaces using `git worktree add` from local main repo copies
- Workspace is ready before the Claude Code CLI is invoked
- Cleanup properly removes worktrees via `git worktree remove`

## Architecture

### Directory Structure

```
{REPO_MAIN_DIRECTORY}/
  waltily/                  ← main bare/standard repo (source for worktrees)
  waltily-frontend/         ← main bare/standard repo (source for worktrees)

{WORKSPACE_PATH}/
  issue-{number}/
    waltily/                ← git worktree of main/waltily on branch feature/issue-{number}
    waltily-frontend/       ← git worktree of main/waltily-frontend on branch feature/issue-{number}
```

### Config

- `config('sdd.repo.main_directory')` → `env('REPO_MAIN_DIRECTORY')` — path to the main repos directory (already exists in `config/sdd.php`)
- `config('sdd.workspace_path')` → base path where `issue-{number}` directories are created

## Changes

### `PreviewService::createWorkspace()` — rewritten

Replace `git clone` logic with:

1. `mkdir -p {workspace}/issue-{number}`
2. For each repo (`waltily`, `waltily-frontend`):
   - `git -C {main_dir}/{repo} worktree add -b feature/issue-{number} {workspace}/issue-{number}/{repo}`
   - If branch already exists (resume case is skipped upstream, but defensive): use `git worktree add` without `-b`

### `PreviewService::teardown()` — updated

After `rm -rf {workspace}`, run for each repo:
- `git -C {main_dir}/{repo} worktree prune`

This cleans up dead worktree references in the main repos.

### `ExecuteTaskJob::handle()` — updated

- On **first run** (`!$isResume`): call `$previewService->setup()` before `$claudeService->execute()`
- On **resume**: skip `setup()` — worktree already exists
- Remove `SetupPreviewJob::dispatch($this->issueNumber)` from end of `handle()`

`setup()` signature used: `$previewService->setup($this->issueNumber, $featureBranch)`

### `SetupPreviewJob` — deleted

The job is removed. No other job dispatches it.

## What Is Not Changed

- `PreviewService::configureEnv()`, `installDependencies()`, `writeNginxConfig()`, `reloadNginx()` — unchanged
- `CleanupJob` — still calls `$previewService->teardown()`, which now also prunes worktrees
- `PreviewEnvironment` model — unchanged
- `DbCloneService` — unchanged
- `CreatePrJob`, `ValidateSpecJob` — unchanged
- `WebhookController` — unchanged (never dispatched `SetupPreviewJob` directly)

## Error Handling

- If `git worktree add` fails (e.g. branch already exists from a prior partial run), the process exception propagates and the job fails — consistent with existing behavior
- `teardown()` uses `worktree prune` which is safe to run even if the worktree directory was already manually removed
