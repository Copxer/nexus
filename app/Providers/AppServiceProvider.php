<?php

namespace App\Providers;

use App\Models\AgentToken;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use App\Policies\AgentTokenPolicy;
use App\Policies\HostPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RepositoryPolicy;
use App\Policies\WebsitePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Repository::class, RepositoryPolicy::class);
        Gate::policy(Website::class, WebsitePolicy::class);
        Gate::policy(Host::class, HostPolicy::class);
        Gate::policy(AgentToken::class, AgentTokenPolicy::class);

        // Per-token rate limiting on `/agent/telemetry` lives inside
        // `AuthenticateAgent` middleware (spec 027). Keeping it there
        // — rather than as a Laravel `throttle:` named limiter — lets
        // the limit fire after token resolution, which a named limiter
        // can't do because Laravel's default middleware priority runs
        // ThrottleRequests before unlisted custom middleware.

        // Force https URL generation when APP_URL is https. Required for
        // Cloudflare/ngrok tunnels: TLS terminates at the tunnel and
        // `php artisan serve` only sees plain HTTP locally, so without
        // this override Laravel emits `http://` URLs that the browser
        // then blocks as Mixed Content. Honors APP_URL only — local-only
        // dev (http://localhost APP_URL) is unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
