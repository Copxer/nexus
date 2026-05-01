<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repository_id')
                ->constrained('repositories')
                ->cascadeOnDelete();

            // GitHub's globally-unique run id; `run_number` is the
            // per-workflow human counter (#N).
            $table->unsignedBigInteger('github_id');
            $table->unsignedInteger('run_number');

            // Workflow display name from GitHub's `name` field —
            // e.g. "CI", "Deploy production".
            $table->string('name');

            // GitHub's `event` field — the trigger: push / pull_request /
            // schedule / workflow_dispatch / release / etc. Not enumerated
            // because GitHub adds new event types over time and we don't
            // gain anything from a tight list here.
            $table->string('event', 32);

            // queued | in_progress | completed (per `WorkflowRunStatus`).
            // Indexed because the timeline UI in spec 021 will filter on it.
            $table->string('status', 16)->index();

            // success | failure | cancelled | timed_out | action_required |
            // stale | neutral | skipped (per `WorkflowRunConclusion`).
            // Nullable: only set once `status = completed`.
            $table->string('conclusion', 16)->nullable()->index();

            $table->string('head_branch')->nullable();
            $table->string('head_sha', 64);

            $table->string('actor_login')->nullable();

            $table->string('html_url');

            // Timestamps from GitHub's payload. `run_started_at` is the
            // post-queue start (preferred for sort), `run_updated_at`
            // mirrors GitHub's last update, `run_completed_at` is set
            // when status flips to completed.
            $table->timestamp('run_started_at')->nullable();
            $table->timestamp('run_updated_at')->nullable();
            $table->timestamp('run_completed_at')->nullable();

            $table->timestamps();

            // Upsert idempotency. The webhook handler (spec 019) and the
            // sync job both land on the same key — last-write-wins.
            $table->unique(['repository_id', 'github_id']);

            // Recent-first listing for the per-repo tab + spec 021's
            // cross-repo timeline. `run_started_at desc` is the natural
            // axis; `github_id desc` is the tie-break for runs that share
            // a started_at timestamp (or both null).
            $table->index(['repository_id', 'run_started_at'], 'workflow_runs_repo_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
