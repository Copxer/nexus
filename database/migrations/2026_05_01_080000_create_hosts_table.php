<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('name');
            // Slug is unique within a project so URLs (28+) and agent
            // self-identification can rely on it. Per-project rather
            // than global so two projects can both have a `prod-01`.
            $table->string('slug', 80);

            // Free-text label of where the host runs (e.g. "DigitalOcean
            // FRA1", "self-hosted"). Pure metadata for now — the agent
            // strategy split (§6.5 of the roadmap) will bind it later.
            $table->string('provider', 32)->nullable();

            // Informational only for `connection_type=agent` (the agent
            // pushes to us). Reserved for ssh/docker_api strategies in
            // a later phase.
            $table->string('endpoint_url', 2048)->nullable();

            // agent | ssh | docker_api | manual (per HostConnectionType
            // enum). Phase 6 only ships the `agent` path; the others
            // exist as data so a host can be migrated without a column
            // rename later.
            $table->string('connection_type', 16)->default('agent');

            // pending | online | offline | degraded | archived (per
            // HostStatus enum). `pending` is the initial state — a host
            // that has never reported telemetry. Transitions to other
            // states arrive in 027 (online on first telemetry) and 029
            // (offline / degraded via the watcher).
            $table->string('status', 16)->default('pending');

            $table->timestamp('last_seen_at')->nullable();

            // Static facts captured from telemetry once the host is
            // online (or filled in by the user at create time). Stored
            // as columns rather than JSON because they're surfaced on
            // the host card and queried in aggregate.
            $table->unsignedSmallInteger('cpu_count')->nullable();
            $table->unsignedInteger('memory_total_mb')->nullable();
            $table->unsignedInteger('disk_total_gb')->nullable();
            $table->string('os', 80)->nullable();
            $table->string('docker_version', 32)->nullable();

            // Free-form bag for fields we don't yet promote to columns
            // (kernel version, agent build, region tags). Bounded by
            // payload validation in 027.
            $table->json('metadata')->nullable();

            // Soft-archive: keep the row for historical reports but hide
            // it from the active list. Pairs with `status=archived`.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosts');
    }
};
