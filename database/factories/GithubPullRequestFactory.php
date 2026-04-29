<?php

namespace Database\Factories;

use App\Enums\GithubPullRequestState;
use App\Models\GithubPullRequest;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GithubPullRequest> */
class GithubPullRequestFactory extends Factory
{
    protected $model = GithubPullRequest::class;

    public function definition(): array
    {
        $state = fake()->randomElement([
            GithubPullRequestState::Open,
            GithubPullRequestState::Open,
            GithubPullRequestState::Merged,
            GithubPullRequestState::Closed,
        ]);

        $createdAt = fake()->dateTimeBetween('-30 days', '-1 day');
        $updatedAt = fake()->dateTimeBetween($createdAt, 'now');
        $isMerged = $state === GithubPullRequestState::Merged;
        $isClosed = $state !== GithubPullRequestState::Open;

        return [
            'repository_id' => Repository::factory(),
            'github_id' => fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'number' => fake()->numberBetween(1, 999),
            'title' => fake()->sentence(6),
            'body_preview' => fake()->sentence(20),
            'state' => $state->value,
            'author_login' => fake()->userName(),
            'base_branch' => 'main',
            'head_branch' => 'feature/'.fake()->slug(2),
            'draft' => false,
            'merged' => $isMerged,
            'additions' => fake()->numberBetween(1, 500),
            'deletions' => fake()->numberBetween(0, 200),
            'changed_files' => fake()->numberBetween(1, 30),
            'comments_count' => fake()->numberBetween(0, 25),
            'review_comments_count' => fake()->numberBetween(0, 10),
            'created_at_github' => $createdAt,
            'updated_at_github' => $updatedAt,
            'closed_at_github' => $isClosed
                ? fake()->dateTimeBetween($updatedAt, 'now')
                : null,
            'merged_at' => $isMerged
                ? fake()->dateTimeBetween($updatedAt, 'now')
                : null,
            'synced_at' => now(),
        ];
    }
}
