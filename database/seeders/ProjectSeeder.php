<?php

namespace Database\Seeders;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProjectSeeder extends Seeder
{
    /**
     * Drop in 4 sample projects across statuses + priorities so the demo
     * dashboard reads as a believable engineering org. Owned by the
     * first existing user — `DatabaseSeeder` creates that user before
     * us, and falls back to a freshly-factoried user if the seeder is
     * run standalone.
     */
    public function run(): void
    {
        $owner = User::query()->oldest('id')->first()
            ?? User::factory()->create(['email_verified_at' => now()]);

        $samples = [
            [
                'name' => 'Customer Portal v3',
                'description' => 'Public-facing customer self-service portal — billing, account, and integrations.',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::High,
                'environment' => 'production',
                'color' => 'cyan',
                'icon' => 'Globe',
                'health_score' => 92,
            ],
            [
                'name' => 'Billing API',
                'description' => 'Subscription, invoicing, and payment-method orchestration service.',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::Critical,
                'environment' => 'production',
                'color' => 'magenta',
                'icon' => 'BarChart3',
                'health_score' => 78,
            ],
            [
                'name' => 'Edge Cache Pilot',
                'description' => 'Experimental edge-caching layer for the public marketing site.',
                'status' => ProjectStatus::Maintenance,
                'priority' => ProjectPriority::Medium,
                'environment' => 'staging',
                'color' => 'purple',
                'icon' => 'Cloud',
                'health_score' => 64,
            ],
            [
                'name' => 'Legacy Reporting Suite',
                'description' => 'Original reporting dashboard, parked behind a feature flag during the rebuild.',
                'status' => ProjectStatus::Paused,
                'priority' => ProjectPriority::Low,
                'environment' => 'internal',
                'color' => 'warning',
                'icon' => 'Database',
                'health_score' => 45,
            ],
        ];

        foreach ($samples as $sample) {
            // DatabaseSeeder uses `WithoutModelEvents`, which disables the
            // `creating` hook that normally auto-populates `slug`. Set it
            // explicitly here so the seeder is robust regardless of how
            // it's invoked.
            Project::query()->updateOrCreate(
                ['name' => $sample['name']],
                array_merge($sample, [
                    'slug' => Str::slug($sample['name']),
                    'owner_user_id' => $owner->id,
                    'last_activity_at' => now()->subMinutes(random_int(2, 240)),
                ]),
            );
        }
    }
}
