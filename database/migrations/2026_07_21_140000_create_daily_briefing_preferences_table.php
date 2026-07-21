<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefing_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->time('delivery_time')->default('08:00:00');
            $table->string('timezone')->default(config('app.timezone', 'UTC'));

            $table->foreignId('channel_id')
                ->nullable()
                ->constrained('alert_notification_channels')
                ->nullOnDelete();

            $table->json('include_projects')->nullable();
            $table->date('last_sent_for_date')->nullable();

            $table->timestamps();

            $table->unique('user_id');
            $table->index(['enabled', 'delivery_time']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefing_preferences');
    }
};
