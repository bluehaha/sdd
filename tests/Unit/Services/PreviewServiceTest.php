<?php

namespace Tests\Unit\Services;

use App\Services\PreviewService;
use PHPUnit\Framework\TestCase;

class PreviewServiceTest extends TestCase
{
    public function test_generate_subdomain(): void
    {
        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces', '/etc/nginx/sites-enabled', '/var/www/sdd/main');
        $subdomain = $service->generateSubdomain(42);
        $this->assertEquals('issue-42.dev.waltily.tw', $subdomain);
    }

    public function test_workspace_path(): void
    {
        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces', '/etc/nginx/sites-enabled', '/var/www/sdd/main');
        $path = $service->issueWorkspacePath(42);
        $this->assertEquals('/var/www/sdd/workspaces/issue-42', $path);
    }
}
