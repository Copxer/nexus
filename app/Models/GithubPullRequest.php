<?php

namespace App\Models;

use App\Enums\GithubPullRequestState;
use Database\Factories\GithubPullRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubPullRequest extends Model
{
    /** @use HasFactory<GithubPullRequestFactory> */
    use HasFactory;

    protected $table = 'github_pull_requests';

    protected $fillable = [
        'repository_id',
        'github_id',
        'number',
        'title',
        'body_preview',
        'state',
        'author_login',
        'base_branch',
        'head_branch',
        'draft',
        'merged',
        'additions',
        'deletions',
        'changed_files',
        'comments_count',
        'review_comments_count',
        'created_at_github',
        'updated_at_github',
        'closed_at_github',
        'merged_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => GithubPullRequestState::class,
            'draft' => 'boolean',
            'merged' => 'boolean',
            'additions' => 'integer',
            'deletions' => 'integer',
            'changed_files' => 'integer',
            'comments_count' => 'integer',
            'review_comments_count' => 'integer',
            'created_at_github' => 'datetime',
            'updated_at_github' => 'datetime',
            'closed_at_github' => 'datetime',
            'merged_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
