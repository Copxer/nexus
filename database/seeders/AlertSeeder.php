<?php

namespace Database\Seeders;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Spec 040 — seed 4 alerts across the lifecycle states so the
 * `/alerts` page + Overview Alerts KPI + the TopBar bell all
 * render with real data on a fresh `db:seed`.
 */
class AlertSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->oldest('id')->first();

        if ($project === null) {
            return;
        }

        // Open critical — most prominent on `/alerts`.
        Alert::query()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 1,
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Marketing site is down',
            'description' => 'GET / returned 503 in 1234ms',
            'triggered_at' => now()->subMinutes(12),
            'last_seen_at' => now()->subMinutes(1),
        ]);

        // Acknowledged warning — user has eyes on it.
        Alert::query()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Deployment->value,
            'source_id' => 1,
            'type' => 'workflow.failed',
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Acknowledged->value,
            'title' => 'CI workflow failed on main',
            'description' => 'Linter step failed: 3 violations',
            'triggered_at' => now()->subHours(2),
            'acknowledged_at' => now()->subMinutes(30),
            'last_seen_at' => now()->subHours(1),
        ]);

        // Resolved (recent history) — shows the close-out path works.
        Alert::query()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Docker->value,
            'source_id' => 1,
            'type' => 'host.offline',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Resolved->value,
            'title' => 'edge-1 went offline',
            'description' => 'No telemetry in 180s',
            'triggered_at' => now()->subHours(4),
            'resolved_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(3),
        ]);

        // Muted — user chose to silence a known flake.
        Alert::query()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 2,
            'type' => 'website.slow',
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Muted->value,
            'title' => 'Billing API health is slow',
            'description' => 'Mean response over 3000ms threshold',
            'triggered_at' => now()->subDay(),
            'last_seen_at' => now()->subHours(6),
        ]);
    }
}
