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
 */
class IssueAgentTokenAction
{
    public function execute(Host $host, ?string $name = null, ?User $createdBy = null): AgentTokenIssueResult
    {
        $plaintext = Str::random(40);

        $token = AgentToken::query()->create([
            'host_id' => $host->id,
            'name' => $name,
            'hashed_token' => AgentToken::hash($plaintext),
            'created_by_user_id' => $createdBy?->id,
        ]);

        return new AgentTokenIssueResult($token, $plaintext);
    }
}
