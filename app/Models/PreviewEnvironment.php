<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviewEnvironment extends Model
{
    protected $fillable = [
        'issue_id',
        'subdomain',
        'workspace_path',
        'cloned_db_name',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function getUrlAttribute(): string
    {
        return "https://{$this->subdomain}";
    }
}
