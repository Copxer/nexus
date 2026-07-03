<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_health_score_weight_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // One row per user; each column mirrors a DEDUCT_* constant in
            // ComputeProjectHealthScoreAction. `null` = "use the default"
            // — reset falls back to the class constant without deleting
            // the row (spec 046 slice A rationale). Bounded to 0..100 by
            // the controller; the score clamp in the action clips the
            // result to `[0, 100]` regardless.
            $columns = [
                'deduct_alert_critical',
                'deduct_alert_warning',
                'deduct_deploy_failed',
                'deduct_website_slow',
                'deduct_website_down',
                'deduct_host_offline',
                'deduct_container_unhealthy',
                'deduct_gh_sync_failed',
            ];
            foreach ($columns as $col) {
                $table->unsignedTinyInteger($col)->nullable();
            }

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_health_score_weight_overrides');
    }
};
