<?php

namespace Tests\Unit\Services;

use App\Services\DbCloneService;
use PHPUnit\Framework\TestCase;

class DbCloneServiceTest extends TestCase
{
    public function test_build_dump_command(): void
    {
        $service = new DbCloneService('waltily', '127.0.0.1', 'root', 'secret');

        $command = $service->buildDumpCommand('/tmp/dump.sql');

        $this->assertEquals([
            'mysqldump',
            '-h', '127.0.0.1',
            '-u', 'root',
            '-psecret',
            'waltily',
            '--result-file=/tmp/dump.sql',
        ], $command);
    }

    public function test_build_import_command(): void
    {
        $service = new DbCloneService('waltily', '127.0.0.1', 'root', 'secret');

        $command = $service->buildImportCommand('waltily_issue_42', '/tmp/dump.sql');

        $this->assertEquals(
            'mysql -h 127.0.0.1 -u root -psecret waltily_issue_42 < /tmp/dump.sql',
            $command
        );
    }

    public function test_cloned_db_name(): void
    {
        $service = new DbCloneService('waltily', '127.0.0.1', 'root', 'secret');

        $this->assertEquals('waltily_issue_42', $service->clonedDbName(42));
        $this->assertEquals('waltily_issue_123', $service->clonedDbName(123));
    }
}
