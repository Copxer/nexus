<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('channel_id')
                ->constrained('alert_notification_channels')
                ->cascadeOnDelete();

            // info | warning | critical — send when alert.severity >= this.
            // Matches AlertSeverity enum values.
            $table->string('min_severity', 16)->default('warning');

            // JSON array of AlertSource values; null / empty = all sources.
            $table->json('sources')->nullable();

            $table->boolean('enabled')->default(true);

            // False by default — resolutions are quieter than triggers.
            // The UI defaults `notify_on_resolve` to true for `critical`
            // + false otherwise; this column stores the resulting choice.
            $table->boolean('notify_on_resolve')->default(false);

            // Overrides the per-channel default (30/hour). Null = default.
            $table->unsignedInteger('rate_limit_per_hour')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notification_preferences');
    }
};
