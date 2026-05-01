<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_metric_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('host_id')
                ->constrained('hosts')
                ->cascadeOnDelete();

            // Percentage utilisation at the snapshot moment. Stored as
            // float — Docker reports up to 1 decimal place.
            $table->float('cpu_percent')->nullable();

            $table->unsignedBigInteger('memory_used_mb')->nullable();
            $table->unsignedBigInteger('memory_total_mb')->nullable();
            $table->unsignedBigInteger('disk_used_gb')->nullable();
            $table->unsignedBigInteger('disk_total_gb')->nullable();

            // Unix `uptime` 1-min load average. Not normalised against
            // CPU count — that's done at render time.
            $table->float('load_average')->nullable();

            $table->unsignedBigInteger('network_rx_bytes')->nullable();
            $table->unsignedBigInteger('network_tx_bytes')->nullable();

            // The agent's clock for when these stats were observed. The
            // `created_at` column captures when the row landed, which is
            // useful for ingestion-lag monitoring.
            $table->timestamp('recorded_at');

            $table->timestamps();

            // Time-series query pattern.
            $table->index(['host_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_metric_snapshots');
    }
};
