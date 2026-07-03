<?php

namespace Tests\Feature\Middleware;

use App\Domain\Palette\Queries\GetPaletteEntitiesQuery;
use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 043 — the pre-loaded palette entity bundle rides via a shared
 * Inertia prop on every authenticated page. Guests get `null`.
 */
class SharedPalettePropTest extends TestCase
{
    use RefreshDatabase;

    public function test_palette_prop_is_null_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $props = $response->viewData('page')['props'] ?? [];
        $this->assertArrayHasKey('palette', $props);
        $this->assertNull($props['palette']);
    }

    public function test_palette_prop_carries_the_users_owned_entities(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $user->id,
            'name' => 'Nexus',
        ]);
        Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'copxer/nexus',
        ]);
        Host::factory()->create([
            'project_id' => $project->id,
            'name' => 'prod-fra-01',
        ]);
        Website::factory()->create([
            'project_id' => $project->id,
            'name' => 'marketing site',
            'url' => 'https://example.com',
        ]);

        $response = $this->actingAs($user)->get(route('overview'));

        $response->assertOk();
        $palette = $response->viewData('page')['props']['palette'] ?? null;

        $this->assertNotNull($palette);
        $this->assertArrayHasKey('entities', $palette);

        $entities = $palette['entities'];
        $this->assertCount(1, $entities['projects']);
        $this->assertSame('Nexus', $entities['projects'][0]['label']);
        $this->assertCount(1, $entities['repositories']);
        $this->assertSame('copxer/nexus', $entities['repositories'][0]['label']);
        $this->assertCount(1, $entities['hosts']);
        $this->assertSame('prod-fra-01', $entities['hosts'][0]['label']);
        $this->assertCount(1, $entities['websites']);
        $this->assertSame('marketing site', $entities['websites'][0]['label']);
    }

    public function test_palette_prop_never_leaks_other_users_entities(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerProject = Project::factory()->create(['owner_user_id' => $stranger->id]);
        Repository::factory()->create(['project_id' => $strangerProject->id]);

        $response = $this->actingAs($owner)->get(route('overview'));

        $palette = $response->viewData('page')['props']['palette'];
        $this->assertSame([], $palette['entities']['projects']);
        $this->assertSame([], $palette['entities']['repositories']);
    }

    public function test_entity_lists_respect_hard_row_caps(): void
    {
        $user = User::factory()->create();

        // ProjectFactory pulls names from a fixed 8-element pool via
        // fake()->unique()->randomElement(), so we insert directly to
        // sidestep the uniqueness tracker for a cap-boundary test.
        $rows = [];
        $now = now();
        for ($i = 0; $i < GetPaletteEntitiesQuery::PROJECTS_CAP + 5; $i++) {
            $rows[] = [
                'name' => "Cap Project #{$i}",
                'slug' => "cap-project-{$i}",
                'owner_user_id' => $user->id,
                'status' => 'active',
                'priority' => 'medium',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Project::query()->insert($rows);

        $response = $this->actingAs($user)->get(route('overview'));
        $palette = $response->viewData('page')['props']['palette'];

        $this->assertCount(
            GetPaletteEntitiesQuery::PROJECTS_CAP,
            $palette['entities']['projects'],
        );
    }
}
