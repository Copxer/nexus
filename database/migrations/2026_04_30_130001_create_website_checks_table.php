<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('website_id')
                ->constrained('websites')
                ->cascadeOnDelete();

            // up | down | slow | error (per WebsiteCheckStatus enum).
            // No `pending` — a recorded check row only exists once a
            // probe ran.
            $table->string('status', 8);

            // Nullable: a transport-level error (DNS, timeout) won't
            // produce an HTTP status code or a response time.
            $table->unsignedSmallInteger('http_status_code')->nullable();
            // Wall-clock time from request dispatch to response receipt
            // — includes DNS / TCP / TLS / send / receive. Spec 023's
            // MVP probe doesn't break out per-leg timings; a future
            // spec adds dns_time_ms / connect_time_ms / tls_time_ms /
            // ttfb_ms columns alongside this aggregate.
            $table->unsignedInteger('response_time_ms')->nullable();

            // Free-text error captured from the exception. Capped at
            // 500 chars in the action layer (parallel to spec 020's
            // sync error storage on repositories).
            $table->text('error_message')->nullable();

            $table->timestamp('checked_at');

            $table->timestamps();

            // Per-website history listing on the Show page.
            $table->index(['website_id', 'checked_at']);
            // Status filter for spec 024's uptime aggregate.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_checks');
    }
};
