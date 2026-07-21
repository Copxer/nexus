<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pull_request_risk_assessments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('github_pull_request_id')
                ->constrained('github_pull_requests')
                ->cascadeOnDelete();

            $table->string('status', 16)->default('pending')->index();
            $table->string('risk_level', 16)->nullable()->index();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->text('summary')->nullable();
            $table->json('reasons')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->string('prompt_version')->default('pr-risk-v1');
            $table->string('model')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique('github_pull_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_risk_assessments');
    }
};
