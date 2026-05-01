<?php

namespace App\Domain\Docker\Actions;

use App\Models\AgentToken;

/**
 * Pair returned by {@see IssueAgentTokenAction}. The plaintext lives
 * here so it travels exactly once — from the action to the controller
 * to the session flash — and never gets persisted, logged, or
 * serialised by accident.
 */
final readonly class AgentTokenIssueResult
{
    public function __construct(
        public AgentToken $token,
        public string $plaintext,
    ) {}
}
