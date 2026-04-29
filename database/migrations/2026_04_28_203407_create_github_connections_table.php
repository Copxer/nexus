<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();

            // One connection per Nexus user — phase 2 is per-user. Org/
            // installation-based connections arrive with multi-team.
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('github_user_id');
            $table->string('github_username');

            // Encrypted at rest via the model's `encrypted` cast. Plaintext
            // is recovered transparently when calling GitHub.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();

            // GitHub user-to-server tokens expire after 8 hours; refresh
            // tokens last ~6 months. Both are nullable for the initial
            // connect (we set them as the OAuth response payload allows).
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();

            // JSON array of granted scopes. Useful for showing the user
            // what we're allowed to do, and for guarding feature flags.
            $table->json('scopes')->nullable();

            $table->timestamp('connected_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
    }
};
