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
     * gate consults `config('horizon.allow_list')`, populated from the
     * `HORIZON_ALLOW_LIST` env var (comma-separated emails).
     *
     * Spec 039 — empty allow-list = no access in production. The
     * deploy flow MUST set `HORIZON_ALLOW_LIST` before the dashboard
     * is reachable.
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

            /** @var array<int, string> $allowList */
            $allowList = config('horizon.allow_list', []);

            return in_array($user->email, $allowList, strict: true);
        });
    }
}
