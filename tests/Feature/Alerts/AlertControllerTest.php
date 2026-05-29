<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Models\Alert;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AlertControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_lists_open_alerts_under_users_projects_by_default(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'title' => 'fresh outage',
        ]);
        // Resolved row should not appear under the default open filter.
        Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'title' => 'old outage',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Alerts/Index')
                    ->has('alerts', 1)
                    ->where('alerts.0.title', 'fresh outage')
                    ->where('filters.status', 'open')
            );
    }

    public function test_index_does_not_leak_alerts_from_sibling_users(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);
        Alert::factory()->create([
            'project_id' => $othersProject->id,
            'title' => "stranger's alert",
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertSuccessful()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('alerts', 0));
    }

    public function test_index_filters_by_severity(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'critical',
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'warning',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index', ['severity' => 'warning']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('alerts', 1)
                    ->where('alerts.0.title', 'warning'),
            );
    }

    public function test_index_filters_by_source(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->forHost()->create(['project_id' => $project->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'title' => 'website',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index', ['source' => 'website']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('alerts', 1)
                    ->where('alerts.0.title', 'website'),
            );
    }

    public function test_index_filters_by_status_acknowledged(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'title' => 'ack',
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'title' => 'open',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index', ['status' => 'acknowledged']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('alerts', 1)
                    ->where('alerts.0.title', 'ack'),
            );
    }

    public function test_index_filters_by_project(): void
    {
        $user = $this->verifiedUser();
        $kept = Project::factory()->create(['owner_user_id' => $user->id]);
        $dropped = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create(['project_id' => $kept->id, 'title' => 'kept']);
        Alert::factory()->create(['project_id' => $dropped->id, 'title' => 'dropped']);

        $this->actingAs($user)
            ->get(route('alerts.index', ['project_id' => $kept->id]))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('alerts', 1)
                    ->where('alerts.0.title', 'kept'),
            );
    }

    public function test_status_all_sentinel_returns_every_status_in_one_list(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create(['project_id' => $project->id]); // open
        Alert::factory()->acknowledged()->create(['project_id' => $project->id]);
        Alert::factory()->resolved()->create(['project_id' => $project->id]);
        Alert::factory()->muted()->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->get(route('alerts.index', ['status' => 'all']))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('alerts', 4)
                    ->where('filters.status', 'all'),
            );
    }

    public function test_index_orders_newest_first_within_the_same_severity(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'older critical',
            'triggered_at' => now()->subMinutes(10),
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'newer critical',
            'triggered_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('alerts', 2)
                    ->where('alerts.0.title', 'newer critical')
                    ->where('alerts.1.title', 'older critical');
            });
    }

    public function test_index_orders_critical_first_then_warning_then_info(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Info->value,
            'title' => 'info-row',
            'triggered_at' => now(),
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'critical-row',
            'triggered_at' => now()->subMinute(),
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'warning-row',
            'triggered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('alerts', 3)
                    ->where('alerts.0.title', 'critical-row')
                    ->where('alerts.1.title', 'warning-row')
                    ->where('alerts.2.title', 'info-row');
            });
    }

    public function test_index_rejects_an_invalid_severity_filter(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->get(route('alerts.index', ['severity' => 'meh']))
            ->assertSessionHasErrors('severity');
    }

    public function test_index_rejects_a_foreign_project_id(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);

        $this->actingAs($user)
            ->get(route('alerts.index', ['project_id' => $othersProject->id]))
            ->assertSessionHasErrors('project_id');
    }

    public function test_index_resolves_affected_entity_url_per_source(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $website = Website::factory()->create(['project_id' => $project->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => $website->id,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
            'title' => 'website row',
        ]);

        $host = Host::factory()->create(['project_id' => $project->id]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Docker->value,
            'source_id' => $host->id,
            'type' => 'host.offline',
            'severity' => AlertSeverity::Critical->value,
            'title' => 'host row',
        ]);

        Alert::factory()->forWorkflowRun()->create([
            'project_id' => $project->id,
            'source_id' => 1234,
            'metadata' => [
                'html_url' => 'https://github.com/org/repo/actions/runs/1234',
            ],
            'title' => 'deployment row',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertInertia(function (AssertableInertia $page) use ($website, $host) {
                $rows = collect($page->toArray()['props']['alerts']);
                $websiteRow = $rows->firstWhere('title', 'website row');
                $hostRow = $rows->firstWhere('title', 'host row');
                $deploymentRow = $rows->firstWhere('title', 'deployment row');

                $this->assertSame(
                    route('monitoring.websites.show', $website->id),
                    $websiteRow['affected_entity_url'],
                );
                $this->assertSame(
                    route('monitoring.hosts.show', $host->id),
                    $hostRow['affected_entity_url'],
                );
                $this->assertSame(
                    'https://github.com/org/repo/actions/runs/1234',
                    $deploymentRow['affected_entity_url'],
                );
            });
    }

    public function test_index_can_action_flags_match_status(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create(['project_id' => $project->id, 'title' => 'open row']);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->where('alerts.0.can_acknowledge', true)
                    ->where('alerts.0.can_resolve', true)
                    ->where('alerts.0.can_mute', true);
            });
    }

    public function test_index_filter_options_carry_enum_values_and_user_projects(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Marketing',
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('filterOptions.severities', 3)
                    ->has('filterOptions.statuses', 4)
                    ->has('filterOptions.sources', 6)
                    ->has('filterOptions.projects', 1)
                    ->where('filterOptions.projects.0.name', 'Marketing');
            });
    }
}
