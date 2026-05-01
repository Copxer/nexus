<?php

namespace Database\Factories;

use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\Repository;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowRun> */
class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    public function definition(): array
    {
        $status = fake()->randomElement([
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::InProgress,
            WorkflowRunStatus::Queued,
        ]);

        $startedAt = fake()->dateTimeBetween('-30 days', '-1 hour');
        $updatedAt = fake()->dateTimeBetween($startedAt, 'now');
        $isCompleted = $status === WorkflowRunStatus::Completed;
        $conclusion = $isCompleted
            ? fake()->randomElement([
                WorkflowRunConclusion::Success,
                WorkflowRunConclusion::Success,
                WorkflowRunConclusion::Success,
                WorkflowRunConclusion::Failure,
                WorkflowRunConclusion::Cancelled,
            ])
            : null;

        $githubId = fake()->unique()->numberBetween(10_000_000, 99_999_999);

        return [
            'repository_id' => Repository::factory(),
            'github_id' => $githubId,
            'run_number' => fake()->numberBetween(1, 999),
            'name' => fake()->randomElement(['CI', 'Deploy', 'Lint', 'Tests']),
            'event' => fake()->randomElement(['push', 'pull_request', 'schedule', 'workflow_dispatch']),
            'status' => $status->value,
            'conclusion' => $conclusion?->value,
            'head_branch' => fake()->randomElement(['main', 'develop', 'topic/'.fake()->slug(2)]),
            'head_sha' => fake()->sha1(),
            'actor_login' => fake()->userName(),
            'html_url' => 'https://github.com/octocat/hello-world/actions/runs/'.$githubId,
            'run_started_at' => $startedAt,
            'run_updated_at' => $updatedAt,
            'run_completed_at' => $isCompleted ? $updatedAt : null,
        ];
    }
}
