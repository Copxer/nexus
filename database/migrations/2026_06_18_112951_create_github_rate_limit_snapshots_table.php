<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 038 — per-user snapshot of GitHub's REST API rate-limit
 * remaining + reset window. Fed by `CheckGitHubRateLimitJob` on a
 * 10-minute cadence; consumed by `GetSystemHealthQuery` for the
 * Settings system-health card and the `github.rate_limit_low`
 * internal alert.
 *
 * One row per snapshot per user — `recorded_at` carries the
 * timestamp the snapshot was taken. The query reads the latest
 * row per user (or the latest row across all users for the global
 * health card). Old snapshots can be pruned by a future
 * housekeeping job; phase-1 keeps them all (low volume — ~144
 * rows per user per day at every-10-minute polling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_rate_limit_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('remaining');
            $table->unsignedInteger('limit');
            $table->timestamp('reset_at');
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_rate_limit_snapshots');
    }
};
