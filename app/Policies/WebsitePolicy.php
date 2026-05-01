<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;

/**
 * Authorization for website monitors. Mirrors `RepositoryPolicy`:
 * create/update/delete/probe are gated to the project owner;
 * viewing is open to any verified user (single-tenant phase-1).
 */
class WebsitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Website $website): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * `create` is project-scoped — invoked via
     * `Gate::authorize('create', [Website::class, $project])`.
     */
    public function create(User $user, ?Project $project): bool
    {
        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function update(User $user, Website $website): bool
    {
        $project = $website->project;

        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function delete(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }

    /** Manual "Probe now" button reuses the update gate. */
    public function probe(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }
}
