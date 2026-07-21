<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // website | docker | deployment | github | manual | system
            // (per AlertSource enum, subset of roadmap §8.12). `github` is
            // reserved for repo/PR-scoped alerts; spec 030 only
            // emits website / docker / deployment.
            $table->string('source', 16);

            // FK target depends on `source` (a Website / Host / WorkflowRun
            // id, respectively). Nullable so `manual` and `system`
            // alerts — emitted by a human or a self-check, with no
            // domain row to point at — fit the same table.
            $table->unsignedBigInteger('source_id')->nullable();

            // Discriminator that pairs with `source` / `source_id` for
            // idempotency. Matches our activity-event vocabulary
            // ('website.down', 'host.offline', 'workflow.failed', …).
            $table->string('type', 64);

            // info | warning | critical (per AlertSeverity enum). Maps
            // 1:1 to ActivitySeverity for the rail.
            $table->string('severity', 16);

            // open | acknowledged | resolved | muted (per AlertStatus
            // enum). The Trigger action only opens; Resolve closes; the
            // 031 UI flips between the four.
            $table->string('status', 16)->default('open');

            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Bumped on every repeat trigger so the UI can show "still
            // firing 5 minutes later" without proliferating rows.
            $table->timestamp('last_seen_at');

            // Free-form bag — url, http_status, error_message for
            // websites; threshold_seconds for hosts; branch + run_id
            // for deployments. Read by the 031 UI's drill-down.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Idempotency lookup in TriggerAlertAction — find the open
            // or acknowledged row for (source, source_id, type).
            $table->index(['status', 'source', 'source_id']);
            // Index-page filters in 031.
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
