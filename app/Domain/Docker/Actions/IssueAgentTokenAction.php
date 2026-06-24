<?php

namespace App\Domain\Docker\Actions;

use App\Models\AgentToken;
use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Mint a new agent bearer token. The plaintext is returned **once** in
 * the result tuple and then never persisted; the database stores only
 * the sha256 hash. Callers (controllers) flash the plaintext to the
 * session so the Vue layer can show it once and then drop it.
 *
 * Spec 039 — optional opt-in to per-request fingerprint binding. When
 * `$fingerprintEnabled = true`, the first successful agent request
 * after this token is issued sets `fingerprint_hash` to
 * `sha256(ip + '|' + user_agent)`; every subsequent request must
 * match. Useful for tokens shipped onto known hosts. Off by default
 * so existing deploys (and casual users) keep working.
 */
class IssueAgentTokenAction
{
    public function execute(
        Host $host,
        ?string $name = null,
        ?User $createdBy = null,
        bool $fingerprintEnabled = false,
    ): AgentTokenIssueResult {
        $plaintext = Str::random(40);

        $token = AgentToken::query()->create([
            'host_id' => $host->id,
            'name' => $name,
            'hashed_token' => AgentToken::hash($plaintext),
            'fingerprint_enabled' => $fingerprintEnabled,
            'created_by_user_id' => $createdBy?->id,
        ]);

        return new AgentTokenIssueResult($token, $plaintext);
    }
}
