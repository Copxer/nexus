<?php

namespace Database\Seeders;

use App\Enums\RepositorySyncStatus;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Seeder;

/**
 * Drop 2-3 repositories per existing seeded project so the Repositories
 * index, the global repositories list, and the project Repositories tab
 * all render with believable data on `migrate:fresh --seed`.
 *
 * Sets `full_name` explicitly because `DatabaseSeeder` uses
 * `WithoutModelEvents`. Pre-fills GitHub-sourced metadata (stars/forks/
 * language/etc.) so the dashboard reads as healthy by default.
 */
class RepositorySeeder extends Seeder
{
    private const REPO_TEMPLATES = [
        // Customer Portal v3 → web + API repos
        'Customer Portal v3' => [
            ['owner' => 'nexus-org', 'name' => 'customer-portal-web', 'language' => 'TypeScript'],
            ['owner' => 'nexus-org', 'name' => 'customer-portal-api', 'language' => 'PHP'],
            ['owner' => 'nexus-org', 'name' => 'customer-portal-mobile', 'language' => 'TypeScript'],
        ],
        'Billing API' => [
            ['owner' => 'nexus-org', 'name' => 'billing-api', 'language' => 'PHP'],
            ['owner' => 'nexus-org', 'name' => 'billing-worker', 'language' => 'Go'],
        ],
        'Edge Cache Pilot' => [
            ['owner' => 'nexus-labs', 'name' => 'edge-cache', 'language' => 'Rust'],
            ['owner' => 'nexus-labs', 'name' => 'edge-config', 'language' => 'TypeScript'],
        ],
        'Legacy Reporting Suite' => [
            ['owner' => 'nexus-org', 'name' => 'legacy-reporting', 'language' => 'PHP'],
            ['owner' => 'nexus-org', 'name' => 'reporting-cron', 'language' => 'Python'],
        ],
    ];

    public function run(): void
    {
        foreach (Project::query()->get() as $project) {
            $templates = self::REPO_TEMPLATES[$project->name] ?? null;

            if ($templates === null) {
                continue;
            }

            foreach ($templates as $i => $template) {
                $full = "{$template['owner']}/{$template['name']}";

                Repository::query()->updateOrCreate(
                    ['full_name' => $full],
                    [
                        'project_id' => $project->id,
                        'provider' => 'github',
                        'provider_id' => (string) random_int(100000, 999999),
                        'owner' => $template['owner'],
                        'name' => $template['name'],
                        'full_name' => $full,
                        'html_url' => "https://github.com/{$full}",
                        'default_branch' => 'main',
                        'visibility' => 'public',
                        'language' => $template['language'],
                        'description' => "{$project->name} — {$template['name']} component.",
                        'stars_count' => random_int(10, 800),
                        'forks_count' => random_int(0, 60),
                        'open_issues_count' => random_int(0, 12),
                        'open_prs_count' => random_int(0, 6),
                        'last_pushed_at' => now()->subHours(random_int(1, 96)),
                        'last_synced_at' => now()->subMinutes(random_int(5, 120)),
                        // First repo per project = synced; rest = mix.
                        'sync_status' => $i === 0
                            ? RepositorySyncStatus::Synced->value
                            : ([
                                RepositorySyncStatus::Synced,
                                RepositorySyncStatus::Synced,
                                RepositorySyncStatus::Pending,
                                RepositorySyncStatus::Failed,
                            ][random_int(0, 3)])->value,
                    ],
                );
            }
        }
    }
}
