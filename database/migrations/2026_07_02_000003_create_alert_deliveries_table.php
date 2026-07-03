<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('alert_id')
                ->constrained('alerts')
                ->cascadeOnDelete();

            $table->foreignId('channel_id')
                ->constrained('alert_notification_channels')
                ->cascadeOnDelete();

            // pending | sent | failed | skipped (per AlertDeliveryStatus).
            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Last failure reason OR skip reason (`rate_limited`,
            // `deduped`, `channel_disabled`, `channel_unverified`).
            $table->text('error_message')->nullable();

            // The outbound payload for forensic replay. Bounded ≤ 4 KB
            // at the service layer — large alert metadata gets truncated
            // with a `[truncated]` marker.
            $table->json('payload')->nullable();

            $table->timestamps();

            // Retry / dedup lookups.
            $table->index(['alert_id', 'channel_id']);
            $table->index(['channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
