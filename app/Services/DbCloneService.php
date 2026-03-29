<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DbCloneService
{
    private string $devDbName;
    private string $devDbHost;
    private string $devDbUser;
    private string $devDbPassword;

    public function __construct(
        ?string $devDbName = null,
        ?string $devDbHost = null,
        ?string $devDbUser = null,
        ?string $devDbPassword = null
    ) {
        $this->devDbName = $devDbName ?? config('sdd.database.dev_db_name');
        $this->devDbHost = $devDbHost ?? config('sdd.database.dev_db_host');
        $this->devDbUser = $devDbUser ?? config('sdd.database.dev_db_user');
        $this->devDbPassword = $devDbPassword ?? config('sdd.database.dev_db_password');
    }

    public function cloneForIssue(int $issueNumber): string
    {
        $clonedName = $this->clonedDbName($issueNumber);
        $dumpPath = storage_path("app/db_dump_issue_{$issueNumber}.sql");

        $this->dump($dumpPath);
        $this->createDatabase($clonedName);
        $this->importDump($clonedName, $dumpPath);

        @unlink($dumpPath);

        Log::info("DB cloned for issue #{$issueNumber}", ['db_name' => $clonedName]);

        return $clonedName;
    }

    public function dropDatabase(string $dbName): void
    {
        DB::statement("DROP DATABASE IF EXISTS `{$dbName}`");
        Log::info("Dropped database: {$dbName}");
    }

    public function clonedDbName(int $issueNumber): string
    {
        return "waltily_issue_{$issueNumber}";
    }

    public function buildDumpCommand(string $outputPath): array
    {
        return [
            'mysqldump',
            '-h', $this->devDbHost,
            '-u', $this->devDbUser,
            "-p{$this->devDbPassword}",
            $this->devDbName,
            "--result-file={$outputPath}",
        ];
    }

    public function buildImportCommand(string $targetDb, string $dumpPath): string
    {
        return "mysql -h {$this->devDbHost} -u {$this->devDbUser} -p{$this->devDbPassword} {$targetDb} < {$dumpPath}";
    }

    private function dump(string $outputPath): void
    {
        $process = new Process($this->buildDumpCommand($outputPath));
        $process->setTimeout(600);
        $process->mustRun();
    }

    private function createDatabase(string $dbName): void
    {
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
    }

    private function importDump(string $targetDb, string $dumpPath): void
    {
        $process = Process::fromShellCommandline($this->buildImportCommand($targetDb, $dumpPath));
        $process->setTimeout(600);
        $process->mustRun();
    }
}
