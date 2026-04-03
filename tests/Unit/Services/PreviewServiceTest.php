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
        $path = $service->workspacePath(42);
        $this->assertEquals('/var/www/sdd/workspaces/issue-42', $path);
    }

    public function test_nginx_config_path(): void
    {
        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces', '/etc/nginx/sites-enabled', '/var/www/sdd/main');
        $path = $service->nginxConfigPath(42);
        $this->assertEquals('/etc/nginx/sites-enabled/sdd-issue-42.conf', $path);
    }

    public function test_generate_nginx_config(): void
    {
        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces', '/etc/nginx/sites-enabled', '/var/www/sdd/main');
        $config = $service->generateNginxConfig(42);
        $this->assertStringContainsString('server_name issue-42.dev.waltily.tw;', $config);
        $this->assertStringContainsString('root /var/www/sdd/workspaces/issue-42/waltily/public;', $config);
        $this->assertStringContainsString('index index.php;', $config);
    }
}
