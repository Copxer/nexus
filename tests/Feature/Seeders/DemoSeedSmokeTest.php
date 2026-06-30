<?php

namespace Tests\Feature\Seeders;

use App\Enums\AlertStatus;
use App\Enums\HostStatus;
use App\Models\Alert;
use App\Models\GithubIssue;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 040 — pin the demo seeder's threshold counts. A regression
 * in any seeder (project / repository / host / website / alert)
 * trips here before the dashboard reads as empty for a new
 * operator running `php artisan migrate:fresh --seed`.
 *
 * Asserts thresholds, not exact rows — the underlying seeders use
 * factories with random ranges, so pinning specific counts /
 * fields would flake.
 */
class DemoSeedSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_populates_every_dashboard_page_with_real_data(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        // Projects + repositories + work items.
        $this->assertGreaterThanOrEqual(4, Project::query()->count());
        $this->assertGreaterThanOrEqual(8, Repository::query()->count());
        // 5 issues per `synced` repo. The seeder marks ~5 of ~10
        // repos synced (first per project + a random subset of the
        // others), so the floor is ~25.
        $this->assertGreaterThanOrEqual(
            20,
            GithubIssue::query()->count(),
            'RepositorySeeder should drop issues onto synced repos',
        );

        // Docker hosts — one online with metric snapshots, one offline.
        $this->assertGreaterThanOrEqual(2, Host::query()->count());
        $this->assertTrue(
            Host::query()->where('status', HostStatus::Online->value)->exists(),
            'HostSeeder should leave at least one host online',
        );
        $this->assertTrue(
            Host::query()->where('status', HostStatus::Offline->value)->exists(),
            'HostSeeder should leave at least one host offline (for the offline-state demo)',
        );

        // Websites with check history.
        $this->assertGreaterThanOrEqual(3, Website::query()->count());

        // Alerts across the lifecycle.
        $this->assertGreaterThanOrEqual(4, Alert::query()->count());
        $this->assertTrue(
            Alert::query()->where('status', AlertStatus::Open->value)->exists(),
            'AlertSeeder should leave at least one open alert (drives Overview Alerts KPI > 0)',
        );
        $this->assertTrue(
            Alert::query()->where('status', AlertStatus::Resolved->value)->exists(),
            'AlertSeeder should leave at least one resolved alert (history)',
        );
    }
}
