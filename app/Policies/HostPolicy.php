<?php

namespace App\Policies;

use App\Models\Host;
use App\Models\Project;
use App\Models\User;

/**
 * Mirrors `WebsitePolicy`: create/update/delete are gated to the
 * project owner; viewing is open to any verified user
 * (single-tenant phase-1).
 */
class HostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Host $host): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * `create` is project-scoped — invoked via
     * `Gate::authorize('create', [Host::class, $project])`.
     */
    public function create(User $user, ?Project $project): bool
    {
        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function update(User $user, Host $host): bool
    {
        $project = $host->project;

        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function delete(User $user, Host $host): bool
    {
        return $this->update($user, $host);
    }

    /** Token issue/rotate/revoke share the host's update gate. */
    public function manageTokens(User $user, Host $host): bool
    {
        return $this->update($user, $host);
    }
}
