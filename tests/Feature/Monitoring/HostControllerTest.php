<?php

namespace Tests\Feature\Monitoring;

use App\Enums\HostStatus;
use App\Models\AgentToken;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HostControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

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
}
