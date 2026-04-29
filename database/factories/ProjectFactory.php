<?php

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Curated mix of realistic-looking engineering project names so the
     * seeded dashboard reads as a believable demo, not lorem-ipsum.
     */
    private const NAME_POOL = [
        'Customer Portal v3',
        'Billing API',
        'Edge Cache Pilot',
        'Internal Notifications',
        'Webhook Replay Service',
        'Staging Cluster Migration',
        'Mobile Web Refresh',
        'Data Platform Kernel',
    ];

    private const COLOR_POOL = ['cyan', 'blue', 'purple', 'magenta', 'success', 'warning'];

    private const ICON_POOL = [
        'FolderKanban', 'Rocket', 'GitBranch', 'Server',
        'Globe', 'BarChart3', 'Bell', 'Activity',
        'HeartPulse', 'Cpu', 'Database', 'Cloud',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(self::NAME_POOL),
            'description' => fake()->sentence(12),
            'status' => fake()->randomElement(ProjectStatus::cases())->value,
            'priority' => fake()->randomElement(ProjectPriority::cases())->value,
            'environment' => fake()->randomElement(['production', 'staging', 'internal', null]),
            'owner_user_id' => User::factory(),
            'color' => fake()->randomElement(self::COLOR_POOL),
            'icon' => fake()->randomElement(self::ICON_POOL),
            'health_score' => fake()->numberBetween(60, 100),
            'last_activity_at' => fake()->dateTimeBetween('-3 days', 'now'),
        ];
    }
}
