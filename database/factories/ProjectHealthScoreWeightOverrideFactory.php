<?php

namespace Database\Factories;

use App\Models\ProjectHealthScoreWeightOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectHealthScoreWeightOverride> */
class ProjectHealthScoreWeightOverrideFactory extends Factory
{
    protected $model = ProjectHealthScoreWeightOverride::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'deduct_alert_critical' => null,
            'deduct_alert_warning' => null,
            'deduct_deploy_failed' => null,
            'deduct_website_slow' => null,
            'deduct_website_down' => null,
            'deduct_host_offline' => null,
            'deduct_container_unhealthy' => null,
            'deduct_gh_sync_failed' => null,
        ];
    }

    /** Convenience: every field takes a specific value. */
    public function withAllWeights(int $value): static
    {
        return $this->state(fn () => [
            'deduct_alert_critical' => $value,
            'deduct_alert_warning' => $value,
            'deduct_deploy_failed' => $value,
            'deduct_website_slow' => $value,
            'deduct_website_down' => $value,
            'deduct_host_offline' => $value,
            'deduct_container_unhealthy' => $value,
            'deduct_gh_sync_failed' => $value,
        ]);
    }
}
