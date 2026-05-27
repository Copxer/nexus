<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

/**
 * Mirrors `HostPolicy` / `WebsitePolicy`: viewing is open to any
 * verified user (single-tenant phase-1; query scoping handles
 * row-level isolation), update / delete are gated to the project owner.
 *
 * The `update` gate covers all three lifecycle verbs — acknowledge,
 * resolve, and mute — since the 031 UI maps each button to an
 * `update`-class controller action.
 */
class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Alert $alert): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Alert $alert): bool
    {
        $project = $alert->project;

        return $project !== null
            && $user->hasVerifiedEmail()
            && $user->can('update', $project);
    }

    public function delete(User $user, Alert $alert): bool
    {
        return $this->update($user, $alert);
    }
}
