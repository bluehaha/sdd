<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class GitHubService
{
    private string $baseUrl = 'https://api.github.com';
    private string $token;
    private string $owner;

    /** @var callable|null */
    private $gitRunner = null;

    public function __construct()
    {
        $this->token = config('sdd.github.token');
        $this->owner = config('sdd.github.owner');
    }

    /**
     * @internal For testing only.
     */
    public function setGitRunner(callable $runner): void
    {
        $this->gitRunner = $runner;
    }

    public function hasChanges(string $repoPath): bool
    {
        $status = $this->runGit(['git', 'status', '--porcelain'], $repoPath);

        return trim($status) !== '';
    }

    public function stageAll(string $repoPath): void
    {
        $this->runGit(['git', 'add', '-A'], $repoPath);
    }

    public function commit(string $repoPath, string $message): void
    {
        $this->runGit(['git', 'commit', '-m', $message], $repoPath);
    }

    public function push(string $repo, string $repoPath, string $branch): void
    {
        $pushUrl = "https://{$this->token}@github.com/{$this->owner}/{$repo}.git";
        $this->runGit(['git', 'push', $pushUrl, $branch], $repoPath);
    }

    private function runGit(array $command, string $cwd): string
    {
        if ($this->gitRunner !== null) {
            return ($this->gitRunner)($command, $cwd);
        }

        $process = new Process($command);
        $process->setWorkingDirectory($cwd);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }

    public function getIssue(string $repo, int $issueNumber): array
    {
        return $this->request('GET', "/repos/{$this->owner}/{$repo}/issues/{$issueNumber}");
    }

    public function postComment(string $repo, int $issueNumber, string $body): array
    {
        return $this->request('POST', "/repos/{$this->owner}/{$repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ]);
    }

    public function addLabel(string $repo, int $issueNumber, string $label): array
    {
        return $this->request('POST', "/repos/{$this->owner}/{$repo}/issues/{$issueNumber}/labels", [
            'labels' => [$label],
        ]);
    }

    public function removeLabel(string $repo, int $issueNumber, string $label): void
    {
        $this->request('DELETE', "/repos/{$this->owner}/{$repo}/issues/{$issueNumber}/labels/{$label}");
    }

    public function createPullRequest(string $repo, string $head, string $base, string $title, string $body): array
    {
        return $this->request('POST', "/repos/{$this->owner}/{$repo}/pulls", [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
        ]);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $response = Http::withToken($this->token)
            ->accept('application/vnd.github+json')
            ->$method("{$this->baseUrl}{$path}", $data);

        $response->throw();

        return $response->json() ?? [];
    }
}
