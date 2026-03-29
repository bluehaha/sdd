<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueLog extends Model
{
    protected $fillable = [
        'issue_id',
        'job_type',
        'session_id',
        'prompt',
        'output',
        'exit_code',
        'duration_seconds',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
