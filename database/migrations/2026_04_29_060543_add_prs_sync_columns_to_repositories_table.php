<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Parallel to spec 015's `issues_sync_status` columns —
            // PRs sync runs as its own job (`SyncRepositoryPullRequestsJob`)
            // chained alongside the issues job, so the lifecycle is
            // observed independently of repo metadata + issues sync.
            $table->string('prs_sync_status', 16)->default('pending')->after('issues_sync_status');
            $table->timestamp('prs_synced_at')->nullable()->after('issues_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['prs_sync_status', 'prs_synced_at']);
        });
    }
};
