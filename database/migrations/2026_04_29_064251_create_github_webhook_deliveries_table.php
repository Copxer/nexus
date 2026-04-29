<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // GitHub's `X-GitHub-Delivery` header — opaque UUID-ish
            // string. Unique so a retry of the same delivery is detected
            // and not double-processed (GitHub retries on timeout).
            $table->string('github_delivery_id')->unique();

            // Top-level event name from the `X-GitHub-Event` header
            // (e.g. `issues`, `pull_request`).
            $table->string('event', 64);

            // The payload's `action` field (e.g. `opened`, `closed`).
            // Nullable because some events don't carry one (`push`).
            $table->string('action', 64)->nullable();

            // Surfaced for filtering / debugging without parsing the
            // payload. Nullable because the `repository` block is
            // missing on a few event types (`ping`, organization-level).
            $table->string('repository_full_name')->nullable()->index();

            // Raw JSON payload for replay + audit. Stored verbatim so
            // we can re-process if a handler bug is fixed later.
            $table->json('payload_json');

            // Raw `X-Hub-Signature-256` header for forensic replay. We
            // don't re-verify on read; this is just an audit trail.
            $table->string('signature');

            $table->string('status', 16)->default('received')->index();

            $table->text('error_message')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['event', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
