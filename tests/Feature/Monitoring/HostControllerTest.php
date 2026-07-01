<?php

namespace Tests\Feature\Monitoring;

use App\Enums\HostStatus;
use App\Models\AgentToken;
use App\Models\Container;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HostControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_hosts_under_users_projects(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'prod-frankfurt-01',
        ]);

        // Sibling user's host must not leak.
        $other = $this->verifiedUser();
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        Host::factory()->create(['project_id' => $otherProject->id]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Index')
                    ->has('hosts', 1)
                    ->where('hosts.0.name', 'prod-frankfurt-01')
            );
    }

    public function test_create_form_renders_with_owned_projects(): void
    {
        $user = $this->verifiedUser();
        Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.create'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Create')
                    ->has('projects', 1)
                    ->has('options.connection_types')
            );
    }

    public function test_store_creates_a_host_for_project_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('monitoring.hosts.store'), [
            'project_id' => $project->id,
            'name' => 'prod-frankfurt-01',
            'provider' => 'DigitalOcean',
            'endpoint_url' => null,
            'connection_type' => 'agent',
        ]);

        $host = Host::query()->firstWhere('name', 'prod-frankfurt-01');
        $this->assertNotNull($host);
        $this->assertSame(HostStatus::Pending, $host->status);
        $this->assertSame('prod-frankfurt-01', $host->slug);
        $response->assertRedirect(route('monitoring.hosts.show', $host));
    }

    public function test_store_assigns_a_unique_slug_per_project(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'prod-frankfurt-01',
            'slug' => 'prod-frankfurt-01',
        ]);

        $this->actingAs($user)->post(route('monitoring.hosts.store'), [
            'project_id' => $project->id,
            'name' => 'prod-frankfurt-01',
            'connection_type' => 'agent',
        ])->assertRedirect();

        $second = Host::query()->latest('id')->first();
        $this->assertNotNull($second);
        $this->assertSame('prod-frankfurt-01-2', $second->slug);
    }

    public function test_store_blocked_for_non_owner_of_target_project(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($other)
            ->post(route('monitoring.hosts.store'), [
                'project_id' => $project->id,
                'name' => 'sneaky',
                'connection_type' => 'agent',
            ])
            ->assertForbidden();

        $this->assertSame(0, Host::query()->count());
    }

    public function test_store_rejects_invalid_connection_type(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('monitoring.hosts.store'), [
                'project_id' => $project->id,
                'name' => 'host',
                'connection_type' => 'wireless-pigeon',
            ])
            ->assertSessionHasErrors('connection_type');
    }

    public function test_show_returns_host_with_active_token_metadata(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        AgentToken::factory()->create(['host_id' => $host->id, 'name' => 'agent v0.1']);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.show', $host))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Show')
                    ->where('host.name', $host->name)
                    ->where('host.active_agent_token.name', 'agent v0.1')
                    ->where('canUpdate', true)
                    ->where('canDelete', true)
                    ->where('canManageTokens', true)
            );
    }

    public function test_show_returns_telemetry_with_current_metrics_and_containers(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'cpu_percent' => 12.5,
            'memory_used_mb' => 1024,
            'memory_total_mb' => 4096,
            'recorded_at' => now()->subMinute(),
        ]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'cpu_percent' => 33.3,
            'memory_used_mb' => 2048,
            'memory_total_mb' => 4096,
            'recorded_at' => now(),
        ]);
        Container::factory()->create([
            'host_id' => $host->id,
            'name' => 'web',
            'image' => 'ghcr.io/acme/web',
            'state' => 'running',
        ]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.show', $host))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Show')
                    // `current` is the latest snapshot. Whole-number
                    // floats are JSON-encoded as ints, so 50.0 → 50.
                    ->where('telemetry.current.cpu_percent', 33.3)
                    ->where('telemetry.current.memory_percent', 50)
                    // Series oldest→newest.
                    ->has('telemetry.series', 2)
                    ->where('telemetry.series.0.cpu_percent', 12.5)
                    ->where('telemetry.series.1.cpu_percent', 33.3)
                    // Containers ordered by name, projected fields present.
                    ->has('telemetry.containers', 1)
                    ->where('telemetry.containers.0.name', 'web')
                    ->where('telemetry.containers.0.state', 'running')
            );
    }

    public function test_show_returns_a_null_current_telemetry_when_the_host_never_reported(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.show', $host))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Show')
                    ->where('telemetry.current', null)
                    ->where('telemetry.series', [])
                    ->where('telemetry.containers', [])
            );
    }

    public function test_show_returns_empty_containers_when_telemetry_exists_without_any(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.show', $host))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('telemetry.containers', [])
                    ->has('telemetry.current')
            );
    }

    public function test_index_exposes_cpu_and_memory_percent_from_the_latest_snapshot(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $reportingHost = Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'host-with-data',
        ]);
        $silentHost = Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'host-no-data',
        ]);
        // Older snapshot — index should ignore it in favour of the latest.
        HostMetricSnapshot::factory()->create([
            'host_id' => $reportingHost->id,
            'cpu_percent' => 1.1,
            'memory_used_mb' => 100,
            'memory_total_mb' => 1000,
            'recorded_at' => now()->subMinute(),
        ]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $reportingHost->id,
            'cpu_percent' => 44.4,
            'memory_used_mb' => 600,
            'memory_total_mb' => 1000,
            'recorded_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('monitoring.hosts.index'))
            ->assertSuccessful()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Monitoring/Hosts/Index')->has('hosts', 2);
                // Index is ordered by host name, so host-no-data is first.
                $page->where('hosts.0.name', 'host-no-data')
                    ->where('hosts.0.cpu_percent', null)
                    ->where('hosts.0.memory_percent', null)
                    ->where('hosts.1.name', 'host-with-data')
                    ->where('hosts.1.cpu_percent', 44.4)
                    ->where('hosts.1.memory_percent', 60);
            });
    }

    public function test_update_changes_the_host_for_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'old',
        ]);

        $response = $this->actingAs($user)->patch(
            route('monitoring.hosts.update', $host),
            [
                'name' => 'new-name',
                'provider' => 'Hetzner',
                'endpoint_url' => null,
            ],
        );

        $host->refresh();
        $this->assertSame('new-name', $host->name);
        $this->assertSame('Hetzner', $host->provider);
        $response->assertRedirect(route('monitoring.hosts.show', $host));
    }

    public function test_destroy_archives_the_host_and_revokes_active_tokens(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $token = AgentToken::factory()->create(['host_id' => $host->id]);

        $this->actingAs($user)
            ->delete(route('monitoring.hosts.destroy', $host))
            ->assertRedirect(route('monitoring.hosts.index'));

        $host->refresh();
        $token->refresh();

        $this->assertSame(HostStatus::Archived, $host->status);
        $this->assertNotNull($host->archived_at);
        $this->assertNotNull($token->revoked_at);
    }

    public function test_destroy_is_idempotent_and_does_not_overwrite_archived_at(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user)->delete(route('monitoring.hosts.destroy', $host));
        $host->refresh();
        $firstArchivedAt = $host->archived_at;
        $this->assertNotNull($firstArchivedAt);

        $this->travel(5)->minutes();

        $this->actingAs($user)->delete(route('monitoring.hosts.destroy', $host));
        $host->refresh();

        // archived_at should be pinned to the first archive, not the
        // second call's `now()`.
        $this->assertTrue($host->archived_at->equalTo($firstArchivedAt));
    }

    public function test_show_blocked_for_unrelated_user(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        // `view` policy is open in phase-1 (mirrors WebsitePolicy), but
        // `canUpdate` / `canDelete` / `canManageTokens` must be false
        // for a non-owner — that's what gates the buttons.
        $this->actingAs($other)
            ->get(route('monitoring.hosts.show', $host))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Hosts/Show')
                    ->where('canUpdate', false)
                    ->where('canDelete', false)
                    ->where('canManageTokens', false)
            );
    }

    public function test_edit_blocked_for_unrelated_user(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->actingAs($other)
            ->get(route('monitoring.hosts.edit', $host))
            ->assertForbidden();
    }

    public function test_update_blocked_for_unrelated_user(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id, 'name' => 'kept']);

        $this->actingAs($other)
            ->patch(route('monitoring.hosts.update', $host), [
                'name' => 'hijacked',
                'provider' => null,
                'endpoint_url' => null,
            ])
            ->assertForbidden();

        $this->assertSame('kept', $host->fresh()->name);
    }

    public function test_destroy_blocked_for_unrelated_user(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->actingAs($other)
            ->delete(route('monitoring.hosts.destroy', $host))
            ->assertForbidden();

        $this->assertNull($host->fresh()->archived_at);
    }
}
