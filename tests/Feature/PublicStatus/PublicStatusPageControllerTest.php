<?php

namespace Tests\Feature\PublicStatus;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\WebsiteStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStatusPageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_for_an_opted_in_project(): void
    {
        $project = Project::factory()->create([
            'public_status_enabled' => true,
            'public_status_headline' => 'Weekly maintenance window.',
        ]);

        $this->get(route('public-status.show', ['project' => $project->slug]))
            ->assertOk();
    }

    public function test_page_404s_for_a_disabled_project(): void
    {
        $project = Project::factory()->create([
            'public_status_enabled' => false,
        ]);

        $this->get(route('public-status.show', ['project' => $project->slug]))
            ->assertNotFound();
    }

    public function test_page_404s_for_an_unknown_slug(): void
    {
        $this->get(route('public-status.show', ['project' => 'nope']))
            ->assertNotFound();
    }

    public function test_overall_band_is_major_outage_when_a_critical_alert_is_open(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        $response = $this->get(route('public-status.show', ['project' => $project->slug]))
            ->assertOk();
        $status = $response->viewData('page')['props']['status'];

        $this->assertSame('major_outage', $status['overall_band']);
        $this->assertSame('Major outage', $status['overall_label']);
    }

    public function test_overall_band_is_operational_when_nothing_open(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Up->value,
        ]);

        $response = $this->get(route('public-status.show', ['project' => $project->slug]))
            ->assertOk();
        $status = $response->viewData('page')['props']['status'];

        $this->assertSame('operational', $status['overall_band']);
    }
}
