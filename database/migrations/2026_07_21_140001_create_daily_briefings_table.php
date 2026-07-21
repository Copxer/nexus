<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('briefing_date');
            $table->string('status', 16)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->text('summary')->nullable();
            $table->json('highlights')->nullable();
            $table->json('risks')->nullable();
            $table->string('prompt_version')->default('daily-briefing-v1');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'briefing_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefings');
    }
};
