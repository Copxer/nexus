<?php

namespace Database\Factories;

use App\Enums\RepositorySyncStatus;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Repository> */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    private const OWNER_POOL = ['nexus-org', 'nexus-labs', 'edge-runtime', 'platform-team'];

    private const NAME_POOL = [
        'nexus-web', 'nexus-api', 'nexus-mail', 'nexus-flags',
        'infra-as-code', 'edge-cache', 'billing-worker', 'analytics-pipeline',
    ];

    private const LANGUAGE_POOL = ['PHP', 'TypeScript', 'Go', 'Rust', 'Python', 'Vue'];

    public function definition(): array
    {
        $owner = fake()->randomElement(self::OWNER_POOL);
        // Append a 4-char suffix so the factory can produce more than
        // NAME_POOL repos without `unique()` overflow. The seeder uses
        // explicit names; this only affects ad-hoc factory calls in tests.
        $name = fake()->randomElement(self::NAME_POOL).'-'.Str::lower(Str::random(4));
        $full = "{$owner}/{$name}";

        // Weight toward `synced` so the seeded dashboard reads as healthy
        // by default. The other states still appear so the badge variants
        // get exercised.
        $status = fake()->randomElement([
            RepositorySyncStatus::Synced,
            RepositorySyncStatus::Synced,
            RepositorySyncStatus::Synced,
            RepositorySyncStatus::Pending,
            RepositorySyncStatus::Syncing,
            RepositorySyncStatus::Failed,
        ]);

        return [
            'project_id' => Project::factory(),
            'provider' => 'github',
            'provider_id' => $status === RepositorySyncStatus::Pending
                ? null
                : (string) fake()->numberBetween(100000, 999999),
            'owner' => $owner,
            'name' => $name,
            'full_name' => $full,
            'html_url' => "https://github.com/{$full}",
            'default_branch' => fake()->randomElement(['main', 'master', 'develop']),
            'visibility' => fake()->randomElement(['public', 'private', 'internal']),
            'language' => fake()->randomElement(self::LANGUAGE_POOL),
            'description' => fake()->sentence(10),
            'stars_count' => fake()->numberBetween(0, 1500),
            'forks_count' => fake()->numberBetween(0, 200),
            'open_issues_count' => fake()->numberBetween(0, 30),
            'open_prs_count' => fake()->numberBetween(0, 15),
            'last_pushed_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'last_synced_at' => $status === RepositorySyncStatus::Pending
                ? null
                : fake()->dateTimeBetween('-1 day', 'now'),
            'sync_status' => $status->value,
        ];
    }
}
