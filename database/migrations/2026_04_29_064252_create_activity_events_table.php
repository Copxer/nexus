<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();

            // Nullable: `repository` events fire before any local row
            // exists (e.g. spec 019's `repository.created` handler may
            // log activity for a repo Nexus doesn't track yet).
            // `nullOnDelete` keeps the audit trail when a repo is
            // unlinked from a project later.
            $table->foreignId('repository_id')
                ->nullable()
                ->constrained('repositories')
                ->nullOnDelete();

            // GitHub username of whoever triggered the event. We don't
            // resolve to a local User row — phase-1 doesn't have a
            // `github_username -> user_id` map (spec 013 stores the
            // current user's username on the connection, but the actor
            // may be someone outside the team).
            $table->string('actor_login')->nullable();

            // Where the event came from. `github` for now; phase 4+
            // will add `nexus`, `monitoring`, `alerts`, etc.
            $table->string('source', 32)->default('github');

            // Dot-namespaced event type, e.g. `issue.created`,
            // `pull_request.merged`. Indexed because the activity
            // feed query filters by it.
            $table->string('event_type', 64)->index();

            $table->string('severity', 16)->default('info')->index();

            $table->string('title');

            $table->text('description')->nullable();

            // Per-event-type structured data — issue/PR number, branch
            // names, workflow run id, etc. Schema deliberately loose
            // here; per-handler shape lives in the handler.
            $table->json('metadata')->nullable();

            // When GitHub says it happened (or now() for nexus-source
            // events). Indexed because the recent-activity query sorts
            // by it descending.
            $table->timestamp('occurred_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
