<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PreviewService
{
    private string $domain;
    private string $workspaceBasePath;
    private string $nginxConfigBasePath;

    public function __construct(
        ?string $domain = null,
        ?string $workspaceBasePath = null,
        ?string $nginxConfigBasePath = null
    ) {
        $this->domain = $domain ?? config('sdd.preview.domain');
        $this->workspaceBasePath = $workspaceBasePath ?? config('sdd.preview.workspace_path');
        $this->nginxConfigBasePath = $nginxConfigBasePath ?? config('sdd.preview.nginx_config_path');
    }

    public function setup(int $issueNumber, string $featureBranch, ?string $clonedDbName = null): string
    {
        $subdomain = $this->generateSubdomain($issueNumber);
        $workspace = $this->workspacePath($issueNumber);

        $this->createWorkspace($workspace, $featureBranch);
        $this->configureEnv($workspace, $subdomain, $clonedDbName);
        $this->installDependencies($workspace);
        $this->writeNginxConfig($issueNumber);
        $this->reloadNginx();

        Log::info("Preview environment created", [
            'issue' => $issueNumber,
            'subdomain' => $subdomain,
        ]);

        return $subdomain;
    }

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

        Log::info("Preview environment removed", ['issue' => $issueNumber]);
    }

    public function generateSubdomain(int $issueNumber): string
    {
        return "issue-{$issueNumber}.{$this->domain}";
    }

    public function workspacePath(int $issueNumber): string
    {
        return "{$this->workspaceBasePath}/issue-{$issueNumber}";
    }

    public function nginxConfigPath(int $issueNumber): string
    {
        return "{$this->nginxConfigBasePath}/sdd-issue-{$issueNumber}.conf";
    }

    public function generateNginxConfig(int $issueNumber): string
    {
        $subdomain = $this->generateSubdomain($issueNumber);
        $workspace = $this->workspacePath($issueNumber);

        return <<<NGINX
server {
    listen 80;
    server_name {$subdomain};
    root {$workspace}/waltily/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX;
    }

    private function createWorkspace(string $workspace, string $featureBranch): void
    {
        @mkdir($workspace, 0755, true);

        foreach (config('sdd.github.target_repos') as $repo) {
            $owner = config('sdd.github.owner');
            $repoUrl = "git@github.com:{$owner}/{$repo}.git";
            $repoPath = "{$workspace}/{$repo}";

            $clone = new Process(['git', 'clone', '--branch', $featureBranch, '--single-branch', $repoUrl, $repoPath]);
            $clone->setTimeout(300);
            $clone->run();

            if (!$clone->isSuccessful()) {
                $fallback = new Process(['git', 'clone', $repoUrl, $repoPath]);
                $fallback->setTimeout(300);
                $fallback->mustRun();

                $checkout = new Process(['git', 'checkout', '-b', $featureBranch], $repoPath);
                $checkout->mustRun();
            }
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

            $build = new Process(['yarn', 'build'], $frontendPath);
            $build->setTimeout(300);
            $build->run();
        }
    }

    private function writeNginxConfig(int $issueNumber): void
    {
        $config = $this->generateNginxConfig($issueNumber);
        $path = $this->nginxConfigPath($issueNumber);
        file_put_contents($path, $config);
    }

    private function reloadNginx(): void
    {
        $process = new Process(['sudo', 'nginx', '-s', 'reload']);
        $process->run();
    }
}
