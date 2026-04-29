<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Reserved for a future multi-team setup. Phase 1 leaves it
            // nullable + indexed so we don't need a backfill migration when
            // teams arrive.
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('status')->default('active')->index();
            $table->string('priority')->default('medium')->index();
            $table->string('environment', 64)->nullable();

            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Token shorthand (cyan|blue|purple|magenta|success|warning) +
            // a curated lucide icon name. Both nullable so projects render
            // with sensible defaults if the user picks neither.
            $table->string('color', 32)->nullable();
            $table->string('icon', 64)->nullable();

            // 0..100 placeholder, populated by future health-scoring jobs.
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
