<?php

namespace Database\Seeders;

use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Database\Seeder;

/**
 * Spec 040 — seed 3 monitored websites so the Monitoring page +
 * Overview uptime KPI show real numbers (not the 100% empty-state
 * floor). Each website gets a 20-minute check history so the
 * spec-025 uptime aggregate has data to crunch.
 */
class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        $projects = Project::query()->oldest('id')->take(3)->get();

        if ($projects->isEmpty()) {
            return;
        }

        $samples = [
            [
                'name' => 'Customer Portal',
                'url' => 'https://portal.example.com',
                'status' => WebsiteStatus::Up,
                'check_distribution' => [
                    WebsiteCheckStatus::Up, WebsiteCheckStatus::Up,
                    WebsiteCheckStatus::Up, WebsiteCheckStatus::Up,
                ],
            ],
            [
                'name' => 'Billing API health',
                'url' => 'https://api.example.com/health',
                'status' => WebsiteStatus::Slow,
                'check_distribution' => [
                    WebsiteCheckStatus::Up, WebsiteCheckStatus::Slow,
                    WebsiteCheckStatus::Up, WebsiteCheckStatus::Slow,
                ],
            ],
            [
                'name' => 'Marketing site',
                'url' => 'https://www.example.com',
                'status' => WebsiteStatus::Down,
                'check_distribution' => [
                    WebsiteCheckStatus::Down, WebsiteCheckStatus::Down,
                    WebsiteCheckStatus::Up, WebsiteCheckStatus::Down,
                ],
            ],
        ];

        foreach ($samples as $i => $sample) {
            $project = $projects->get($i % $projects->count());

            $website = Website::query()->create([
                'project_id' => $project->id,
                'name' => $sample['name'],
                'url' => $sample['url'],
                'method' => 'GET',
                'expected_status_code' => 200,
                'timeout_ms' => 10_000,
                'check_interval_seconds' => 300,
                'status' => $sample['status']->value,
                'last_checked_at' => now()->subMinutes(2),
                'last_success_at' => $sample['status'] === WebsiteStatus::Down
                    ? now()->subHours(6)
                    : now()->subMinutes(2),
                'last_failure_at' => in_array(
                    $sample['status'],
                    [WebsiteStatus::Down, WebsiteStatus::Slow, WebsiteStatus::Error],
                    strict: true,
                ) ? now()->subMinutes(2) : null,
            ]);

            // 20-minute history at 5-minute intervals.
            foreach ($sample['check_distribution'] as $j => $checkStatus) {
                WebsiteCheck::query()->create([
                    'website_id' => $website->id,
                    'status' => $checkStatus->value,
                    'http_status_code' => $checkStatus === WebsiteCheckStatus::Error
                        ? null
                        : ($checkStatus === WebsiteCheckStatus::Down ? 503 : 200),
                    'response_time_ms' => $checkStatus === WebsiteCheckStatus::Error ? null : random_int(80, 2800),
                    'error_message' => $checkStatus === WebsiteCheckStatus::Error
                        ? 'Connection refused'
                        : null,
                    'checked_at' => now()->subMinutes(($j + 1) * 5),
                ]);
            }
        }
    }
}
