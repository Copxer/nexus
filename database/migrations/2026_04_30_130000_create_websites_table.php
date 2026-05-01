<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('url');

            // HTTP method for the probe; phase-1 supports GET/HEAD/POST.
            // Stored as a short string rather than an enum so we can
            // accept new RFC-listed methods later without a migration.
            $table->string('method', 8)->default('GET');

            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->unsignedInteger('timeout_ms')->default(10_000);
            $table->unsignedInteger('check_interval_seconds')->default(300);

            // pending | up | down | slow | error (per WebsiteStatus enum).
            // `pending` is the initial state — created but never probed.
            $table->string('status', 16)->default('pending');

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            $table->timestamps();

            // Per-project listing + status filter on the index page.
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
