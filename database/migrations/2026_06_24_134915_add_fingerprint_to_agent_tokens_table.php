<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 039 — opt-in agent fingerprinting per §16.5. When
 * `fingerprint_enabled = true`, `AuthenticateAgent` middleware
 * computes `sha256(ip + '|' + user_agent)` on every request and
 * compares against `fingerprint_hash`:
 *
 *   - Hash null → first-binding, persist + continue.
 *   - Hash matches → pass.
 *   - Hash mismatches → 401 + `agent.auth.failure` activity event.
 *
 * Default is `false` so every pre-039 token (and every new token
 * issued without the flag set) keeps working. Operators can rotate
 * a token with the flag enabled to harden the binding to the
 * specific host the binary is running on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tokens', function (Blueprint $table): void {
            $table->boolean('fingerprint_enabled')->default(false)->after('hashed_token');
            $table->string('fingerprint_hash', 64)->nullable()->after('fingerprint_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tokens', function (Blueprint $table): void {
            $table->dropColumn(['fingerprint_enabled', 'fingerprint_hash']);
        });
    }
};
