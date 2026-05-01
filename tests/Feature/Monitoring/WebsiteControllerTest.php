<?php

namespace Tests\Feature\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebsiteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_lists_websites_under_users_projects(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Website::factory()->create([
            'project_id' => $project->id,
            'name' => 'Marketing site',
        ]);

        // Sibling user's website must not leak.
        $other = $this->verifiedUser();
        $otherProject = Project::factory()->create(['owner_user_id' => $other->id]);
        Website::factory()->create(['project_id' => $otherProject->id]);

        $this->actingAs($user)
            ->get(route('monitoring.websites.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Websites/Index')
                    ->has('websites', 1)
                    ->where('websites.0.name', 'Marketing site')
            );
    }

    public function test_create_form_renders_with_owned_projects(): void
    {
        $user = $this->verifiedUser();
        Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('monitoring.websites.create'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Websites/Create')
                    ->has('projects', 1)
                    ->has('options.methods')
                    ->has('options.common_intervals')
            );
    }

    public function test_store_creates_a_website_for_project_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('monitoring.websites.store'), [
            'project_id' => $project->id,
            'name' => 'Marketing site',
            'url' => 'https://example.com/health',
            'method' => 'GET',
            'expected_status_code' => 200,
            'timeout_ms' => 10_000,
            'check_interval_seconds' => 300,
        ]);

        $website = Website::query()->firstWhere('name', 'Marketing site');
        $this->assertNotNull($website);
        $this->assertSame('https://example.com/health', $website->url);
        $response->assertRedirect(route('monitoring.websites.show', $website));
    }

    public function test_store_rejects_invalid_url(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('monitoring.websites.store'), [
                'project_id' => $project->id,
                'name' => 'Bad URL',
                'url' => 'not a url',
                'method' => 'GET',
                'expected_status_code' => 200,
                'timeout_ms' => 10_000,
                'check_interval_seconds' => 300,
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_store_blocked_for_non_owner_of_target_project(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($other)
            ->post(route('monitoring.websites.store'), [
                'project_id' => $project->id,
                'name' => 'Sneaky site',
                'url' => 'https://example.com',
                'method' => 'GET',
                'expected_status_code' => 200,
                'timeout_ms' => 10_000,
                'check_interval_seconds' => 300,
            ])
            ->assertForbidden();

        $this->assertSame(0, Website::query()->count());
    }

    public function test_show_returns_website_with_recent_checks(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        WebsiteCheck::factory()->count(3)->create(['website_id' => $website->id]);

        $this->actingAs($user)
            ->get(route('monitoring.websites.show', $website))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Monitoring/Websites/Show')
                    ->has('website')
                    ->has('checks', 3)
                    ->where('canUpdate', true)
                    ->where('canDelete', true)
                    ->where('canProbe', true)
            );
    }

    public function test_update_changes_the_website_for_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create([
            'project_id' => $project->id,
            'name' => 'Old name',
        ]);

        $response = $this->actingAs($user)->patch(
            route('monitoring.websites.update', $website),
            [
                'name' => 'New name',
                'url' => $website->url,
                'method' => 'GET',
                'expected_status_code' => 200,
                'timeout_ms' => 10_000,
                'check_interval_seconds' => 300,
            ],
        );

        $website->refresh();
        $this->assertSame('New name', $website->name);
        $response->assertRedirect(route('monitoring.websites.show', $website));
    }

    public function test_update_blocked_for_non_owner(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->actingAs($other)
            ->patch(route('monitoring.websites.update', $website), [
                'name' => 'Hijacked',
                'url' => $website->url,
                'method' => 'GET',
                'expected_status_code' => 200,
                'timeout_ms' => 10_000,
                'check_interval_seconds' => 300,
            ])
            ->assertForbidden();
    }

    public function test_destroy_deletes_for_owner(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->delete(route('monitoring.websites.destroy', $website))
            ->assertRedirect(route('monitoring.websites.index'));

        $this->assertNull(Website::query()->find($website->id));
    }
}
