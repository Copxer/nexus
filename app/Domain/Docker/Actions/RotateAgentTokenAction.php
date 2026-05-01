<?php

namespace App\Domain\Docker\Actions;

use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke every currently-active token on a host and mint a fresh one.
 * Wrapped in a transaction so the gap between "old token revoked" and
 * "new token live" is invisible to the agent.
 */
class RotateAgentTokenAction
{
    public function __construct(
        private IssueAgentTokenAction $issue,
    ) {}

    public function execute(Host $host, ?string $name = null, ?User $rotatedBy = null): AgentTokenIssueResult
    {
        return DB::transaction(function () use ($host, $name, $rotatedBy): AgentTokenIssueResult {
            $host->agentTokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $this->issue->execute($host, $name, $rotatedBy);
        });
    }
}
