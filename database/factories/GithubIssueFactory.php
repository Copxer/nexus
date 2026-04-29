<?php

namespace Database\Factories;

use App\Enums\GithubIssueState;
use App\Models\GithubIssue;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GithubIssue> */
class GithubIssueFactory extends Factory
{
    protected $model = GithubIssue::class;

    public function definition(): array
    {
        $state = fake()->randomElement([
            GithubIssueState::Open,
            GithubIssueState::Open,
            GithubIssueState::Open,
            GithubIssueState::Closed,
        ]);

        $createdAt = fake()->dateTimeBetween('-30 days', '-1 day');
        $updatedAt = fake()->dateTimeBetween($createdAt, 'now');

        return [
            'repository_id' => Repository::factory(),
            'github_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'number' => fake()->numberBetween(1, 999),
            'title' => fake()->sentence(6),
            'body_preview' => fake()->sentence(20),
            'state' => $state->value,
            'author_login' => fake()->userName(),
            'labels' => [
                ['name' => 'bug', 'color' => 'd73a4a'],
            ],
            'assignees' => [fake()->userName()],
            'milestone' => null,
            'comments_count' => fake()->numberBetween(0, 25),
            'is_locked' => false,
            'created_at_github' => $createdAt,
            'updated_at_github' => $updatedAt,
            'closed_at_github' => $state === GithubIssueState::Closed
                ? fake()->dateTimeBetween($updatedAt, 'now')
                : null,
            'synced_at' => now(),
        ];
    }
}
