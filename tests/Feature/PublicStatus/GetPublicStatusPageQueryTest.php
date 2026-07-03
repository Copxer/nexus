<?php

namespace Tests\Feature\PublicStatus;

use App\Domain\PublicStatus\Queries\GetPublicStatusPageQuery;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GetPublicStatusPageQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_contains_monitors_active_and_recent_incidents(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $website = Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Up->value,
        ]);
        WebsiteCheck::factory()->create([
            'website_id' => $website->id,
            'status' => WebsiteCheckStatus::Up->value,
            'checked_at' => now()->subMinutes(5),
        ]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Live alert',
        ]);
        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Resolved->value,
            'title' => 'Historical alert',
            'resolved_at' => now()->subHour(),
        ]);

        Cache::flush();
        $snapshot = app(GetPublicStatusPageQuery::class)->execute($project);

        $this->assertCount(1, $snapshot->monitors);
        $this->assertSame('Live alert', $snapshot->activeIncidents[0]['title']);
        $this->assertSame('Historical alert', $snapshot->recentIncidents[0]['title']);
    }

    public function test_snapshot_is_cached(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $cacheKey = GetPublicStatusPageQuery::cacheKey($project->id);

        Cache::flush();
        app(GetPublicStatusPageQuery::class)->execute($project);

        $this->assertTrue(Cache::has($cacheKey));
    }
}
