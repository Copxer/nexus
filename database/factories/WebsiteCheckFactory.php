<?php

namespace Database\Factories;

use App\Enums\WebsiteCheckStatus;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebsiteCheck> */
class WebsiteCheckFactory extends Factory
{
    protected $model = WebsiteCheck::class;

    public function definition(): array
    {
        $status = fake()->randomElement([
            WebsiteCheckStatus::Up,
            WebsiteCheckStatus::Up,
            WebsiteCheckStatus::Up,
            WebsiteCheckStatus::Slow,
            WebsiteCheckStatus::Down,
            WebsiteCheckStatus::Error,
        ]);

        return [
            'website_id' => Website::factory(),
            'status' => $status->value,
            'http_status_code' => $status === WebsiteCheckStatus::Error
                ? null
                : fake()->randomElement([200, 200, 200, 500, 503, 404]),
            'response_time_ms' => $status === WebsiteCheckStatus::Error
                ? null
                : fake()->numberBetween(50, 4_000),
            'error_message' => $status === WebsiteCheckStatus::Error
                ? 'Connection timed out after 10000ms'
                : null,
            'checked_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }
}
