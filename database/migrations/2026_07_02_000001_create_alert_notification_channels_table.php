<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notification_channels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // email | slack | webhook (per NotificationChannelKind enum).
            $table->string('kind', 16);

            $table->string('name');

            // Per-kind payload: email → {to}; slack → {webhook_url};
            // webhook → {url, signing_secret?}. Kept as encrypted JSON so
            // Slack webhook URLs + optional HMAC secrets never sit in
            // plaintext (spec 039 posture).
            $table->text('config');

            $table->boolean('enabled')->default(true);

            // Set after the "send test" round-trip succeeds. Delivery
            // skips un-verified channels — prevents an operator from
            // pasting a bad Slack URL and only finding out on the first
            // real critical alert.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notification_channels');
    }
};
