<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('host_id')
                ->constrained('hosts')
                ->cascadeOnDelete();

            // Optional project pin — set when the container's labels
            // identify it as belonging to a specific project. Defaults
            // null; the agent reconciliation in 027 fills it where it
            // can.
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            // Docker's own container ID (12 or 64 char hex). Unique per
            // host because Docker recycles short IDs across hosts.
            $table->string('container_id', 80);

            $table->string('name');
            $table->string('image');
            $table->string('image_tag', 128)->nullable();

            // running | exited | created | paused | restarting | dead.
            // Stored as string so Docker's evolving status set doesn't
            // require migrations.
            $table->string('status', 32)->nullable();

            // Same string as `status` historically — kept distinct
            // because Docker's `State` object is richer (boolean
            // running, paused, etc.). MVP just mirrors `status`; future
            // probes can populate fine-grained fields.
            $table->string('state', 32)->nullable();

            // healthy | unhealthy | starting | none. Sourced from
            // Docker healthcheck output when present.
            $table->string('health_status', 16)->nullable();

            $table->json('ports')->nullable();
            $table->json('labels')->nullable();

            // Latest stat sample. The full time series goes into
            // container_metric_snapshots; these columns power the
            // current-state UI without a join.
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_usage_mb')->nullable();
            $table->unsignedBigInteger('memory_limit_mb')->nullable();
            $table->float('memory_percent')->nullable();
            $table->unsignedBigInteger('network_rx_bytes')->nullable();
            $table->unsignedBigInteger('network_tx_bytes')->nullable();
            $table->unsignedBigInteger('block_read_bytes')->nullable();
            $table->unsignedBigInteger('block_write_bytes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['host_id', 'container_id']);
            $table->index(['host_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
