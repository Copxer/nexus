<?php

namespace App\Policies;

use App\Models\AgentToken;
use App\Models\User;

/**
 * Token authorization defers to the parent host. We never expose a
 * "list all my tokens" view, so `viewAny` is intentionally absent.
 */
class AgentTokenPolicy
{
    public function view(User $user, AgentToken $token): bool
    {
        return $token->host !== null
            && $user->can('view', $token->host);
    }

    public function delete(User $user, AgentToken $token): bool
    {
        return $token->host !== null
            && $user->can('manageTokens', $token->host);
    }
}
