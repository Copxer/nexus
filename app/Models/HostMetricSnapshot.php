<?php

namespace App\Models;

use Database\Factories\HostMetricSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostMetricSnapshot extends Model
{
    /** @use HasFactory<HostMetricSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'host_id',
        'cpu_percent',
        'memory_used_mb',
        'memory_total_mb',
        'disk_used_gb',
        'disk_total_gb',
        'load_average',
        'network_rx_bytes',
        'network_tx_bytes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'load_average' => 'float',
            'memory_used_mb' => 'integer',
            'memory_total_mb' => 'integer',
            'disk_used_gb' => 'integer',
            'disk_total_gb' => 'integer',
            'network_rx_bytes' => 'integer',
            'network_tx_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
