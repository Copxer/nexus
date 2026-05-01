<?php

namespace App\Models;

use Database\Factories\ContainerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Container extends Model
{
    /** @use HasFactory<ContainerFactory> */
    use HasFactory;

    protected $fillable = [
        'host_id',
        'project_id',
        'container_id',
        'name',
        'image',
        'image_tag',
        'status',
        'state',
        'health_status',
        'ports',
        'labels',
        'cpu_percent',
        'memory_usage_mb',
        'memory_limit_mb',
        'memory_percent',
        'network_rx_bytes',
        'network_tx_bytes',
        'block_read_bytes',
        'block_write_bytes',
        'started_at',
        'finished_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'ports' => 'array',
            'labels' => 'array',
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'memory_usage_mb' => 'integer',
            'memory_limit_mb' => 'integer',
            'network_rx_bytes' => 'integer',
            'network_tx_bytes' => 'integer',
            'block_read_bytes' => 'integer',
            'block_write_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(ContainerMetricSnapshot::class);
    }
}
