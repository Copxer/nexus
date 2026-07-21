<?php

namespace Database\Factories;

use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectHealthExplanation> */
class ProjectHealthExplanationFactory extends Factory
{
    protected $model = ProjectHealthExplanation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => ProjectHealthExplanationStatus::Pending->value,
            'health_score' => 75,
            'health_band' => HealthScoreBand::Good->value,
            'summary' => null,
            'drivers' => [],
            'recommended_actions' => [],
            'input_snapshot' => null,
            'prompt_version' => 'project-health-explanation-v1',
            'model' => null,
            'explained_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];
    }

    public function explained(): static
    {
        return $this->state(fn () => [
            'status' => ProjectHealthExplanationStatus::Explained->value,
            'health_score' => 42,
            'health_band' => HealthScoreBand::Warning->value,
            'summary' => 'Health dropped because alerts and failed checks increased.',
            'drivers' => ['2 critical alerts', '1 failing website check'],
            'recommended_actions' => ['Investigate the critical alerts first'],
            'input_snapshot' => ['project' => ['health_score' => 42]],
            'model' => 'claude-3-5-haiku-latest',
            'explained_at' => now(),
        ]);
    }
}
