<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // Provider stays a string for now (only GitHub today). When a
            // second provider arrives we'll formalize as an enum.
            $table->string('provider', 32)->default('github');

            // GitHub repo numeric id. Populated by phase-2 sync; null on
            // manual link.
            $table->string('provider_id')->nullable();

            $table->string('owner');
            $table->string('name');

            // `owner/name` — used as the route key. Unique to prevent
            // double-linking the same repo to multiple projects (the
            // LinkRepositoryToProjectAction handles same-project idempotency
            // before this index is reached).
            $table->string('full_name')->unique();

            $table->string('html_url');
            $table->string('default_branch', 64)->default('main');
            $table->string('visibility', 16)->default('public');
            $table->string('language', 64)->nullable();
            $table->text('description')->nullable();

            $table->unsignedInteger('stars_count')->default(0);
            $table->unsignedInteger('forks_count')->default(0);
            $table->unsignedInteger('open_issues_count')->default(0);
            $table->unsignedInteger('open_prs_count')->default(0);

            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->string('sync_status', 16)->default('pending')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
