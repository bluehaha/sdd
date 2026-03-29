<?php

namespace Tests\Unit\Models;

use App\Enums\IssueStatus;
use PHPUnit\Framework\TestCase;

class IssueStatusTest extends TestCase
{
    public function test_all_statuses_exist(): void
    {
        $this->assertCount(7, IssueStatus::cases());
    }

    public function test_status_values(): void
    {
        $this->assertEquals('pending', IssueStatus::Pending->value);
        $this->assertEquals('spec_validating', IssueStatus::SpecValidating->value);
        $this->assertEquals('spec_passed', IssueStatus::SpecPassed->value);
        $this->assertEquals('developing', IssueStatus::Developing->value);
        $this->assertEquals('preview_ready', IssueStatus::PreviewReady->value);
        $this->assertEquals('approved', IssueStatus::Approved->value);
        $this->assertEquals('done', IssueStatus::Done->value);
    }

    public function test_status_from_string(): void
    {
        $this->assertEquals(IssueStatus::Pending, IssueStatus::from('pending'));
        $this->assertEquals(IssueStatus::Developing, IssueStatus::from('developing'));
    }
}
