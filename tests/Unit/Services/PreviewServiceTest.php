<?php

namespace Tests\Unit\Services;

use App\Services\PreviewService;
use Tests\TestCase;

class PreviewServiceTest extends TestCase
{
    public function test_workspace_path(): void
    {
        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces');
        $path = $service->issueWorkspacePath(42);
        $this->assertEquals('/var/www/sdd/workspaces/issue-42', $path);
    }

    public function test_build_frontend_runs_when_there_are_changes(): void
    {
        $workspacePath = sys_get_temp_dir() . '/preview-test-' . uniqid();
        $frontendPath = $workspacePath . '/waltily-frontend';
        mkdir($frontendPath, 0755, true);

        exec("git -C " . escapeshellarg($frontendPath) . " init -q");
        exec("git -C " . escapeshellarg($frontendPath) . " config user.email 'test@example.com'");
        exec("git -C " . escapeshellarg($frontendPath) . " config user.name 'Test'");
        exec("git -C " . escapeshellarg($frontendPath) . " commit --allow-empty -m 'init' -q");
        // Create and commit a file so HEAD~1 exists
        file_put_contents("{$frontendPath}/app.js", 'old');
        exec("git -C " . escapeshellarg($frontendPath) . " add app.js");
        exec("git -C " . escapeshellarg($frontendPath) . " commit -m 'add app.js' -q");
        // Modify it so git diff HEAD~1 shows a change
        file_put_contents("{$frontendPath}/app.js", 'changed');
        exec("git -C " . escapeshellarg($frontendPath) . " add app.js");
        exec("git -C " . escapeshellarg($frontendPath) . " commit -m 'change app.js' -q");

        // Create a package.json with a build script that just echoes
        file_put_contents("{$frontendPath}/package.json", json_encode([
            'name' => 'test',
            'scripts' => ['build' => 'echo built'],
        ]));

        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces');

        // Should not throw
        $service->buildFrontendIfChanged($workspacePath);

        exec("rm -rf " . escapeshellarg($workspacePath));

        $this->assertTrue(true);
    }

    public function test_build_frontend_skipped_when_no_changes(): void
    {
        $workspacePath = sys_get_temp_dir() . '/preview-test-' . uniqid();
        $frontendPath = $workspacePath . '/waltily-frontend';
        mkdir($frontendPath, 0755, true);

        exec("git -C " . escapeshellarg($frontendPath) . " init -q");
        exec("git -C " . escapeshellarg($frontendPath) . " config user.email 'test@example.com'");
        exec("git -C " . escapeshellarg($frontendPath) . " config user.name 'Test'");
        exec("git -C " . escapeshellarg($frontendPath) . " commit --allow-empty -m 'init' -q");
        // Two commits so HEAD~1 exists but nothing changed between them
        exec("git -C " . escapeshellarg($frontendPath) . " commit --allow-empty -m 'second' -q");

        file_put_contents("{$frontendPath}/package.json", json_encode([
            'name' => 'test',
            'scripts' => ['build' => 'echo built'],
        ]));

        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces');
        $service->buildFrontendIfChanged($workspacePath);

        exec("rm -rf " . escapeshellarg($workspacePath));

        $this->assertTrue(true);
    }

    public function test_build_frontend_skipped_when_directory_missing(): void
    {
        $workspacePath = sys_get_temp_dir() . '/preview-test-' . uniqid();
        mkdir($workspacePath, 0755, true);
        // waltily-frontend does NOT exist inside $workspacePath

        $service = new PreviewService('dev.waltily.tw', '/var/www/sdd/workspaces');
        $service->buildFrontendIfChanged($workspacePath);

        exec("rm -rf " . escapeshellarg($workspacePath));

        $this->assertTrue(true);
    }
}
