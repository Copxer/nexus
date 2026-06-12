<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 036 — per-user theme preference. Three values:
 *
 *   - `dark`   (default) — the existing dark-only UI through Phase 8.
 *   - `light`            — the spec-036 baseline light palette.
 *   - `system`           — derive from `prefers-color-scheme` at
 *                          mount time on the frontend.
 *
 * Stored as a short string instead of an enum constraint so future
 * additions (eg. a future "sepia" or "high-contrast" preset) don't
 * require a migration. The controller validates the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme', 16)->default('dark')->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
