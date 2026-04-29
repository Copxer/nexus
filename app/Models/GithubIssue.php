<?php

namespace App\Models;

use App\Enums\GithubIssueState;
use Database\Factories\GithubIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubIssue extends Model
{
    /** @use HasFactory<GithubIssueFactory> */
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'github_id',
        'number',
        'title',
        'body_preview',
        'state',
        'author_login',
        'labels',
        'assignees',
        'milestone',
        'comments_count',
        'is_locked',
        'created_at_github',
        'updated_at_github',
        'closed_at_github',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => GithubIssueState::class,
            'labels' => 'array',
            'assignees' => 'array',
            'milestone' => 'array',
            'comments_count' => 'integer',
            'is_locked' => 'boolean',
            'created_at_github' => 'datetime',
            'updated_at_github' => 'datetime',
            'closed_at_github' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
