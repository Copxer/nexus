<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name', 120);

            // queue.backlog_trend | deploy_frequency_drop |
            // uptime_slope | deploy_failure_rate — matches
            // `AlertRuleKind` enum. Kept dotted where the concept
            // maps 1:1 to an existing alert `type` naming pattern.
            $table->string('kind', 32);

            // info | warning | critical — the fired alert's severity.
            $table->string('severity', 16)->default('warning');

            // Per-kind knobs: threshold delta, window minutes, drop
            // percent, slope threshold. Shape validated at the
            // controller / evaluator boundary.
            $table->json('config')->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();

            // Minimum minutes between successive triggers so a stuck
            // condition doesn't page an operator every tick. 30-minute
            // default matches spec 042's dedupe window rationale.
            $table->unsignedInteger('cool_down_minutes')->default(30);

            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
