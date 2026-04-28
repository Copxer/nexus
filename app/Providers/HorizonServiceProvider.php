<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * In local + testing environments any authenticated, email-verified
     * user gets in — phase 0 is single-developer dev, an explicit allow-
     * list is friction without value. In any other environment the
     * gate falls back to an explicit allow-list, populated when the
     * production deploy flow lands in phase 9.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($user === null) {
                return false;
            }

            if (app()->environment('local', 'testing')) {
                return $user->hasVerifiedEmail();
            }

            return in_array($user->email, [
                // TODO(phase-9): populate this allow-list before deploying.
            ], strict: true);
        });
    }
}
