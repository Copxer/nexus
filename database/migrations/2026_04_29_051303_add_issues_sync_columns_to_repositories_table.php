<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Issues sync runs as its own job (`SyncRepositoryIssuesJob`)
            // chained after the repo metadata sync, so we track its
            // lifecycle independently of the existing `sync_status`.
            $table->string('issues_sync_status', 16)->default('pending')->after('sync_status');
            $table->timestamp('issues_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['issues_sync_status', 'issues_synced_at']);
        });
    }
};
