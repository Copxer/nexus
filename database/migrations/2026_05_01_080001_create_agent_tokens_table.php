<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('host_id')
                ->constrained('hosts')
                ->cascadeOnDelete();

            // Optional human label so a user can rotate tokens without
            // losing context ("rotated 2026-05-01", "agent v0.2"). The
            // active token also doubles as the install-command label.
            $table->string('name', 80)->nullable();

            // sha256 of the plaintext bearer token. Plaintext is shown
            // to the user once at issuance/rotation and then discarded.
            // Unique so middleware can `where(hashed_token = ?)` and
            // hit the index.
            $table->string('hashed_token', 64)->unique();

            $table->timestamp('last_used_at')->nullable();

            // Set when the token is rotated or explicitly revoked. The
            // hash stays in the row so a stolen plaintext can be traced
            // post-incident; middleware ignores rows where this is set.
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Active-token lookup pattern: by host, scoped to non-revoked
            // rows. Index covers the host_id half; the revoked filter is
            // small enough to satisfy at scan time.
            $table->index(['host_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};
