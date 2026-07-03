<?php

namespace Database\Factories;

use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Queue backlog watch',
            'kind' => AlertRuleKind::QueueBacklogTrend->value,
            'severity' => AlertSeverity::Warning->value,
            'config' => [
                'window_minutes' => 15,
                'threshold_delta' => 50,
            ],
            'enabled' => true,
            'last_evaluated_at' => null,
            'last_triggered_at' => null,
            'cool_down_minutes' => 30,
        ];
    }

    public function ofKind(AlertRuleKind $kind, array $config = []): static
    {
        return $this->state(fn () => [
            'kind' => $kind->value,
            'config' => array_merge($this->defaultConfigFor($kind), $config),
            'name' => $kind->label(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function inCoolDown(): static
    {
        return $this->state(fn () => [
            'last_triggered_at' => now()->subMinutes(5),
            'cool_down_minutes' => 30,
        ]);
    }

    private function defaultConfigFor(AlertRuleKind $kind): array
    {
        return match ($kind) {
            AlertRuleKind::QueueBacklogTrend => [
                'window_minutes' => 15,
                'threshold_delta' => 50,
            ],
            AlertRuleKind::DeployFrequencyDrop => [
                'window_days' => 7,
                'drop_percent' => 50,
            ],
            AlertRuleKind::UptimeSlope => [
                'window_hours' => 24,
                'slope_threshold' => -1.0,
            ],
            AlertRuleKind::DeployFailureRate => [
                'sample_size' => 10,
                'failure_rate_percent' => 30,
            ],
        };
    }
}
