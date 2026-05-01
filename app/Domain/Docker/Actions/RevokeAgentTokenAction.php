<?php

namespace App\Domain\Docker\Actions;

use App\Models\AgentToken;

class RevokeAgentTokenAction
{
    public function execute(AgentToken $token): AgentToken
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return $token;
    }
}
