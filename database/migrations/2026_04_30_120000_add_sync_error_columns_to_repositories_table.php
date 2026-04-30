<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Three independent sync flows (metadata / issues / PRs) each
            // track their own status + synced_at. Mirror that for failure
            // state: a `*_sync_error` (truncated message — see jobs) and
            // `*_sync_failed_at` per flow. On a successful run we clear
            // both so the row reflects current state, not stale failures.
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('sync_failed_at')->nullable()->after('last_synced_at');

            $table->text('issues_sync_error')->nullable()->after('issues_sync_status');
            $table->timestamp('issues_sync_failed_at')->nullable()->after('issues_synced_at');

            $table->text('prs_sync_error')->nullable()->after('prs_sync_status');
            $table->timestamp('prs_sync_failed_at')->nullable()->after('prs_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn([
                'sync_error',
                'sync_failed_at',
                'issues_sync_error',
                'issues_sync_failed_at',
                'prs_sync_error',
                'prs_sync_failed_at',
            ]);
        });
    }
};
