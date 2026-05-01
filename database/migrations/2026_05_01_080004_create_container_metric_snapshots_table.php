<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_metric_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('container_id')
                ->constrained('containers')
                ->cascadeOnDelete();

            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_usage_mb')->nullable();
            $table->unsignedBigInteger('memory_limit_mb')->nullable();
            $table->float('memory_percent')->nullable();
            $table->unsignedBigInteger('network_rx_bytes')->nullable();
            $table->unsignedBigInteger('network_tx_bytes')->nullable();
            $table->unsignedBigInteger('block_read_bytes')->nullable();
            $table->unsignedBigInteger('block_write_bytes')->nullable();

            $table->timestamp('recorded_at');

            $table->timestamps();

            $table->index(['container_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_metric_snapshots');
    }
};
