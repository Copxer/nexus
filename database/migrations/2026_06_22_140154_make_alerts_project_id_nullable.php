<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 038 — `AlertSource::System` alerts (queue backlog, GitHub
 * rate-limit, webhook failure rate, agent auth failures) don't
 * belong to any single project. They're Nexus-wide signals
 * surfaced by `EvaluateSystemHealthJob`. The original alerts
 * table required a `project_id` because spec 030 only emitted
 * project-scoped alerts (website / docker / deployment).
 *
 * Relax the column to nullable + flip the FK from `cascadeOnDelete`
 * to `nullOnDelete` so deleting a project doesn't take down system
 * alerts that happen to be open at the same time. Project-scoped
 * alerts still cascade naturally because they all carry a real
 * project_id and the FK still fires `null` on parent delete (which
 * orphans the row but doesn't destroy data — operators can clean
 * up if needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            // Drop the existing FK + column, then re-add with the
            // nullable + nullOnDelete shape. Doctrine DBAL isn't
            // installed; this is the portable path across SQLite + MySQL.
            $table->dropForeign(['project_id']);
        });

        Schema::table('alerts', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->change();

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
        });

        Schema::table('alerts', function (Blueprint $table): void {
            // Restoring the not-null + cascadeOnDelete shape: any
            // existing null rows must be deleted first to satisfy
            // the constraint. The spec 038 system alerts are
            // ephemeral anyway.
            DB::table('alerts')->whereNull('project_id')->delete();

            $table->unsignedBigInteger('project_id')->nullable(false)->change();

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete();
        });
    }
};
