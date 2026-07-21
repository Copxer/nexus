<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_health_explanations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('health_score');
            $table->string('health_band', 16)->nullable()->index();
            $table->text('summary')->nullable();
            $table->json('drivers')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->string('prompt_version')->default('project-health-explanation-v1');
            $table->string('model')->nullable();
            $table->timestamp('explained_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_health_explanations');
    }
};
