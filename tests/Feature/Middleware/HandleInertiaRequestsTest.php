<?php

namespace Tests\Feature\Middleware;

use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Spec 032 — `alerts.activeCount` is shared by
 * `HandleInertiaRequests::share()` so the TopBar bell badge updates
 * across every Inertia render. Exercised through the Overview page,
 * which is the cheapest Inertia route that doesn't require any
 * specific page-payload setup.
 */
class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_alerts_active_count_counts_open_plus_acknowledged_for_the_auth_user(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        Alert::factory()->create(['project_id' => $project->id]);
        Alert::factory()->acknowledged()->create(['project_id' => $project->id]);
        Alert::factory()->resolved()->create(['project_id' => $project->id]);
        Alert::factory()->muted()->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('alerts.activeCount', 2),
            );
    }

    public function test_alerts_active_count_does_not_leak_sibling_alerts(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);
        Alert::factory()->create(['project_id' => $othersProject->id]);
        Alert::factory()->acknowledged()->create(['project_id' => $othersProject->id]);

        $this->actingAs($user)
            ->get(route('overview'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page->where('alerts.activeCount', 0),
            );
    }

    public function test_alerts_shared_prop_is_null_for_guests(): void
    {
        // Any unauthenticated Inertia route. `/` renders Welcome via
        // Inertia and is open to guests per routes/web.php.
        $this->get(route('login'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->where('alerts', null),
            );
    }
}
