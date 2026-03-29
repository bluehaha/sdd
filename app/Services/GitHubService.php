<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GitHubService
{
    private string $baseUrl = 'https://api.github.com';
    private string $token;
    private string $owner;

    public function __construct()
    {
        $this->token = config('sdd.github.token');
        $this->owner = config('sdd.github.owner');
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
