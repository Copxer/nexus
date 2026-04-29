<?php

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;

/**
 * Authorization for repositories. Phase 1 ties create/delete to the
 * parent project's owner — only the project owner can link or unlink
 * repos under their project. Anyone authenticated and verified can
 * view (consistent with `ProjectPolicy::view`).
 *
 * No `update` action this spec — repository metadata is GitHub-sourced
 * (phase 2). Add an `update` if a "rename project link" feature ever
 * surfaces.
 */
class RepositoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Repository $repository): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Linking is a project-scoped action; the policy is invoked via
     * `Gate::authorize('create', [Repository::class, $project])` from
     * the form request and the controller.
     */
    public function create(User $user, $project): bool
    {
        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function delete(User $user, Repository $repository): bool
    {
        $project = $repository->project;

        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }
}
