<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repository_id')
                ->constrained('repositories')
                ->cascadeOnDelete();

            // GitHub's globally-unique issue id. The repo-local `number`
            // is the human #N you see in the UI; both come from the API.
            $table->unsignedBigInteger('github_id');
            $table->unsignedInteger('number')->index();

            $table->string('title');

            // Trimmed to 280 chars by NormalizeGitHubIssueAction. Full body
            // lives on GitHub; we only persist a preview to keep rows sane.
            $table->text('body_preview')->nullable();

            // GitHub only exposes open|closed for issues. Spec 016's PRs
            // get a richer derived-status set in their own table.
            $table->string('state', 16)->default('open')->index();

            $table->string('author_login')->nullable();

            $table->json('labels')->nullable();
            $table->json('assignees')->nullable();
            $table->json('milestone')->nullable();

            $table->unsignedInteger('comments_count')->default(0);
            $table->boolean('is_locked')->default(false);

            $table->timestamp('created_at_github')->nullable();
            $table->timestamp('updated_at_github')->nullable()->index();
            $table->timestamp('closed_at_github')->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Re-sync upserts target this composite key, keeping the row
            // count stable when the same payload lands twice.
            $table->unique(['repository_id', 'github_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_issues');
    }
};
