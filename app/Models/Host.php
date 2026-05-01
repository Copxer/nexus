<?php

namespace App\Models;

use App\Enums\HostConnectionType;
use App\Enums\HostStatus;
use Database\Factories\HostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Host extends Model
{
    /** @use HasFactory<HostFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'provider',
        'endpoint_url',
        'connection_type',
        'status',
        'last_seen_at',
        'cpu_count',
        'memory_total_mb',
        'disk_total_gb',
        'os',
        'docker_version',
        'metadata',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'connection_type' => HostConnectionType::class,
            'status' => HostStatus::class,
            'last_seen_at' => 'datetime',
            'archived_at' => 'datetime',
            'cpu_count' => 'integer',
            'memory_total_mb' => 'integer',
            'disk_total_gb' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agentTokens(): HasMany
    {
        return $this->hasMany(AgentToken::class);
    }

    /**
     * The currently-active agent token for this host. Null when none
     * exists or all have been revoked. Only one is expected to be
     * active at a time — `RotateAgentTokenAction` revokes the previous
     * before issuing the next.
     */
    public function activeAgentToken(): HasOne
    {
        return $this->hasOne(AgentToken::class)->whereNull('revoked_at')->latestOfMany();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(HostMetricSnapshot::class);
    }
}
