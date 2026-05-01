<?php

namespace App\Models;

use Database\Factories\ContainerMetricSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerMetricSnapshot extends Model
{
    /** @use HasFactory<ContainerMetricSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'container_id',
        'cpu_percent',
        'memory_usage_mb',
        'memory_limit_mb',
        'memory_percent',
        'network_rx_bytes',
        'network_tx_bytes',
        'block_read_bytes',
        'block_write_bytes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'memory_usage_mb' => 'integer',
            'memory_limit_mb' => 'integer',
            'network_rx_bytes' => 'integer',
            'network_tx_bytes' => 'integer',
            'block_read_bytes' => 'integer',
            'block_write_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
