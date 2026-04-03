<?php

namespace App\Services;

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

    public function setup(int $issueNumber, string $featureBranch, ?string $clonedDbName = null): string
    {
        $subdomain = $this->generateSubdomain($issueNumber);
        $workspace = $this->issueWorkspacePath($issueNumber);

        $this->createWorkspace($workspace, $featureBranch);
        $this->configureEnv($workspace, $subdomain, $clonedDbName);
        // $this->installDependencies($workspace);

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

    public function generateSubdomain(int $issueNumber): string
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

    private function configureEnv(string $workspace, string $subdomain, ?string $clonedDbName): void
    {
        $envSource = "{$workspace}/waltily/.env.example";
        $envTarget = "{$workspace}/waltily/.env";

        if (file_exists($envSource)) {
            copy($envSource, $envTarget);
        }

        if (file_exists($envTarget)) {
            $env = file_get_contents($envTarget);
            $env = preg_replace('/^APP_URL=.*/m', "APP_URL=https://{$subdomain}", $env);
            if ($clonedDbName) {
                $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$clonedDbName}", $env);
            }
            file_put_contents($envTarget, $env);
        }
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
