<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Parallel to spec 015's `issues_sync_*` and spec 016's
            // `prs_sync_*` columns — workflow runs sync runs as its own
            // job (`SyncRepositoryWorkflowRunsJob`) chained alongside
            // them, so its lifecycle is observed independently of the
            // metadata / issues / PRs syncs.
            //
            // Same six-column shape (status / synced_at / error /
            // failed_at) shipped for the other flows in `add_sync_error
            // _columns_to_repositories_table` — keeping it aligned so
            // the UI can render all four sync flows from one template.
            $table->string('workflow_runs_sync_status', 16)->default('pending')->after('prs_sync_status');
            $table->text('workflow_runs_sync_error')->nullable()->after('workflow_runs_sync_status');
            $table->timestamp('workflow_runs_synced_at')->nullable()->after('prs_synced_at');
            $table->timestamp('workflow_runs_sync_failed_at')->nullable()->after('workflow_runs_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_runs_sync_status',
                'workflow_runs_sync_error',
                'workflow_runs_synced_at',
                'workflow_runs_sync_failed_at',
            ]);
        });
    }
};
