<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_pull_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repository_id')
                ->constrained('repositories')
                ->cascadeOnDelete();

            // GitHub's globally-unique PR id; `number` is the human #N.
            $table->unsignedBigInteger('github_id');
            $table->unsignedInteger('number')->index();

            $table->string('title');

            // Same firm 280-char cap as github_issues.body_preview.
            $table->text('body_preview')->nullable();

            // Three values only: open|closed|merged. Derived by the
            // normalizer from GitHub's `state` + `merged` boolean.
            // The richer derived states (draft, needs_review, …) ride
            // along with phase-9's review/check sync.
            $table->string('state', 16)->default('open')->index();

            $table->string('author_login')->nullable();

            // Branch names — base = target, head = source. GitHub
            // returns these as `base.ref` / `head.ref`.
            $table->string('base_branch');
            $table->string('head_branch');

            $table->boolean('draft')->default(false);
            $table->boolean('merged')->default(false);

            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changed_files')->default(0);

            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('review_comments_count')->default(0);

            $table->timestamp('created_at_github')->nullable();
            $table->timestamp('updated_at_github')->nullable()->index();
            $table->timestamp('closed_at_github')->nullable();
            $table->timestamp('merged_at')->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['repository_id', 'github_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_pull_requests');
    }
};
