<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Pending = 'pending';
    case SpecValidating = 'spec_validating';
    case SpecPassed = 'spec_passed';
    case Developing = 'developing';
    case PreviewReady = 'preview_ready';
    case Approved = 'approved';
    case Done = 'done';
}
