<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\PreviewEnvironment;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PreviewService
{
    private string $domain;
    private string $workspaceBasePath;

    public function __construct(
        ?string $domain = null,
        ?string $workspaceBasePath = null,
    ) {
        $this->domain = $domain ?? config('sdd.preview.domain');
        $this->workspaceBasePath = $workspaceBasePath ?? config('sdd.workspace_path');
    }

    public function setup(Issue $issue): string
    {
        $issueNumber = $issue->issue_number;
        $featureBranch = $issue->feature_branch;
        $subdomain = $this->generateSubdomain($issueNumber);
        $mainWorkspace = $this->mainWorkspacePath();
        $issueWorkspace = $this->issueWorkspacePath($issueNumber);

        $this->createWorkspace($issueWorkspace, $featureBranch);
        $this->configureClaude($issueWorkspace);
        $this->configureEnv($mainWorkspace, $issueWorkspace, $subdomain);
        $this->installDependencies($issueWorkspace);

        PreviewEnvironment::create([
            'issue_id' => $issue->id,
            'subdomain' => $subdomain,
            'workspace_path' => $issueWorkspace,
        ]);

        Log::info("Preview environment created", [
            'issue' => $issueNumber,
            'subdomain' => $subdomain,
        ]);

        return $subdomain;
    }

    public function teardown(int $issueNumber): void
    {
        $workspace = $this->issueWorkspacePath($issueNumber);

        if (is_dir($workspace)) {
            $process = new Process(['rm', '-rf', $workspace]);
            $process->mustRun();
        }

        foreach (config('sdd.github.target_repos') as $repo) {
            $mainRepo = $this->mainWorkspacePath() . "/{$repo}";
            $prune = new Process(['git', '-C', $mainRepo, 'worktree', 'prune']);
            $prune->setTimeout(30);
            $prune->run();
        }

        Log::info("Preview environment removed", ['issue' => $issueNumber]);
    }

    public function buildFrontend(string $issueWorkspacePath): void
    {
        $frontendPath = $issueWorkspacePath . '/waltily-frontend';

        $build = new Process(['yarn', 'build']);
        $build->setWorkingDirectory($frontendPath);
        $build->setTimeout(300);
        $build->run();

        if (!$build->isSuccessful()) {
            Log::error('Frontend build failed', [
                'path' => $frontendPath,
                'error' => $build->getErrorOutput(),
                'output' => $build->getOutput(),
            ]);
            return;
        }

        Log::info('Frontend build completed', ['path' => $frontendPath]);
    }

    private function generateSubdomain(int $issueNumber): string
    {
        return "issue-{$issueNumber}.{$this->domain}";
    }

    public function issueWorkspacePath(int $issueNumber): string
    {
        return "{$this->workspaceBasePath}/issue-{$issueNumber}";
    }

    private function mainWorkspacePath(): string
    {
        return "{$this->workspaceBasePath}/main";
    }

    private function createWorkspace(string $workspace, string $featureBranch): void
    {
        @mkdir($workspace, 0755, true);

        foreach (config('sdd.github.target_repos') as $repo) {
            $mainRepo = $this->mainWorkspacePath() . "/{$repo}";
            $worktreePath = "{$workspace}/{$repo}";

            $process = new Process(
                ['git', '-C', $mainRepo, 'worktree', 'add', '-b', $featureBranch, $worktreePath],
            );
            $process->setTimeout(60);
            $process->mustRun();
        }
    }

    private function configureClaude(string $workspace): void
    {
        $source = resource_path('SDD_CLAUDE.md');
        $target = "{$workspace}/CLAUDE.md";

        copy($source, $target);
    }

    private function configureEnv(string $mainWorkspace, string $issueWorkspace, string $subdomain): void
    {
        $envSource = "{$mainWorkspace}/waltily/.env";
        $envTarget = "{$issueWorkspace}/waltily/.env";

        $env = file_get_contents($envSource);
        $env = preg_replace('/^APP_URL=.*/m', "APP_URL=https://{$subdomain}", $env);
        file_put_contents($envTarget, $env);
    }

    private function installDependencies(string $workspace): void
    {
        $backendPath = "{$workspace}/waltily";
        if (file_exists("{$backendPath}/composer.json")) {
            $process = new Process(['composer', 'install', '--no-interaction'], $backendPath);
            $process->setTimeout(300);
            $process->run();
        }

        $frontendPath = "{$workspace}/waltily-frontend";
        if (file_exists("{$frontendPath}/package.json")) {
            $install = new Process(['yarn', 'install'], $frontendPath);
            $install->setTimeout(300);
            $install->run();
        }
    }
}
