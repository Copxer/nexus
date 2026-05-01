<?php

namespace App\Models;

use App\Enums\RepositorySyncStatus;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'provider',
        'provider_id',
        'owner',
        'name',
        'full_name',
        'html_url',
        'default_branch',
        'visibility',
        'language',
        'description',
        'stars_count',
        'forks_count',
        'open_issues_count',
        'open_prs_count',
        'last_pushed_at',
        'last_synced_at',
        'sync_status',
        'sync_error',
        'sync_failed_at',
        'issues_sync_status',
        'issues_synced_at',
        'issues_sync_error',
        'issues_sync_failed_at',
        'prs_sync_status',
        'prs_synced_at',
        'prs_sync_error',
        'prs_sync_failed_at',
        'workflow_runs_sync_status',
        'workflow_runs_synced_at',
        'workflow_runs_sync_error',
        'workflow_runs_sync_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_status' => RepositorySyncStatus::class,
            'issues_sync_status' => RepositorySyncStatus::class,
            'prs_sync_status' => RepositorySyncStatus::class,
            'workflow_runs_sync_status' => RepositorySyncStatus::class,
            'stars_count' => 'integer',
            'forks_count' => 'integer',
            'open_issues_count' => 'integer',
            'open_prs_count' => 'integer',
            'last_pushed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_failed_at' => 'datetime',
            'issues_synced_at' => 'datetime',
            'issues_sync_failed_at' => 'datetime',
            'prs_synced_at' => 'datetime',
            'prs_sync_failed_at' => 'datetime',
            'workflow_runs_synced_at' => 'datetime',
            'workflow_runs_sync_failed_at' => 'datetime',
        ];
    }

    /**
     * `owner/name` — two-segment URL, mirroring GitHub. The matching
     * route registration in `routes/web.php` adds a regex `where()`
     * constraint so Laravel's binder accepts the slash.
     */
    public function getRouteKeyName(): string
    {
        return 'full_name';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(GithubIssue::class);
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(GithubPullRequest::class);
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
