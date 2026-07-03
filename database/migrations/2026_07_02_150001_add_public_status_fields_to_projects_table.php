<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Spec 047 — per-project opt-in for the public status page.
            // Default false so existing projects stay private on migrate;
            // operators flip it via Settings → Project.
            $table->boolean('public_status_enabled')->default(false)->after('slug');

            // Operator-provided banner line above the incidents strip.
            // Kept short (varchar 240) — a status page headline should
            // fit on one line at typical widths.
            $table->string('public_status_headline', 240)->nullable()->after('public_status_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['public_status_enabled', 'public_status_headline']);
        });
    }
};
