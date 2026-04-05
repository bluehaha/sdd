<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Issue extends Model
{
    protected $fillable = [
        'issue_number',
        'title',
        'body',
        'github_author',
        'status',
        'spec_session_id',
        'dev_session_id',
        'feature_branch',
    ];

    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IssueLog::class);
    }

    public function previewEnvironment(): HasOne
    {
        return $this->hasOne(PreviewEnvironment::class);
    }
}
