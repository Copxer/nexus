<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Anyone authenticated and email-verified can list projects. Phase 1
     * is single-tenant; team scoping arrives with the multi-team spec.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /** Same baseline as viewAny — every verified user can read any project. */
    public function view(User $user, Project $project): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /** Edit / delete are owner-only until shared-team policies arrive. */
    public function update(User $user, Project $project): bool
    {
        return $project->owner_user_id === $user->id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->owner_user_id === $user->id;
    }
}
