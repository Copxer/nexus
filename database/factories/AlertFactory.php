<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        $triggeredAt = now()->subMinutes(fake()->numberBetween(0, 30));

        return [
            'project_id' => Project::factory(),
            'source' => AlertSource::Website->value,
            'source_id' => fake()->numberBetween(1, 1000),
            'type' => 'website.down',
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
            'title' => 'Marketing site went down',
            'description' => 'GET / returned 500 in 1234ms',
            'triggered_at' => $triggeredAt,
            'acknowledged_at' => null,
            'resolved_at' => null,
            'last_seen_at' => $triggeredAt,
            'metadata' => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status' => AlertStatus::Acknowledged->value,
            'acknowledged_at' => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => AlertStatus::Resolved->value,
            'resolved_at' => now(),
        ]);
    }

    public function muted(): static
    {
        return $this->state(fn () => [
            'status' => AlertStatus::Muted->value,
        ]);
    }

    public function forHost(): static
    {
        return $this->state(fn () => [
            'source' => AlertSource::Docker->value,
            'type' => 'host.offline',
            'title' => 'prod-frankfurt-01 went offline',
            'description' => 'No telemetry in 120s',
        ]);
    }

    public function forWorkflowRun(): static
    {
        return $this->state(fn () => [
            'source' => AlertSource::Deployment->value,
            'severity' => AlertSeverity::Warning->value,
            'type' => 'workflow.failed',
            'title' => 'Workflow failed on main',
            'description' => 'CI run #421 failed',
        ]);
    }
}
