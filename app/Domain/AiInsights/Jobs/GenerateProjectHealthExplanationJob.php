<?php

namespace App\Domain\AiInsights\Jobs;

use App\Domain\AiInsights\Actions\GenerateProjectHealthExplanationAction;
use App\Domain\AiInsights\Queries\GetProjectHealthExplanationInputQuery;
use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class GenerateProjectHealthExplanationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $projectId) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(GetProjectHealthExplanationInputQuery $inputQuery, GenerateProjectHealthExplanationAction $generate): void
    {
        if (! config('services.llm.enabled', false)) {
            $this->markSkippedByProjectId('AI features are disabled.');

            return;
        }

        $project = Project::query()->find($this->projectId);
        $owner = $project?->owner_user_id === null ? null : User::query()->find($project->owner_user_id);

        if ($project === null || $owner === null) {
            return;
        }

        $this->markPending($project);

        $snapshot = $inputQuery->execute($owner, $project);

        if ($snapshot === null) {
            $this->markSkipped($project, 'Project is no longer available in the scoped AI input query.');

            return;
        }

        $generate->execute($project, $snapshot);
    }

    public function failed(Throwable $exception): void
    {
        $explanation = ProjectHealthExplanation::query()
            ->where('project_id', $this->projectId)
            ->where('status', ProjectHealthExplanationStatus::Pending->value)
            ->first();

        if ($explanation === null) {
            return;
        }

        $explanation->forceFill([
            'status' => ProjectHealthExplanationStatus::Failed,
            'failed_at' => now(),
            'error_message' => Str::limit($exception->getMessage(), 2_000, ''),
        ])->save();
    }

    private function markPending(Project $project): void
    {
        $explanation = ProjectHealthExplanation::query()
            ->where('project_id', $project->id)
            ->first() ?? new ProjectHealthExplanation(['project_id' => $project->id]);

        $explanation->forceFill([
            'status' => ProjectHealthExplanationStatus::Pending,
            'health_score' => $project->health_score ?? 0,
            'health_band' => HealthScoreBand::fromScore($project->health_score ?? 0),
            'failed_at' => null,
            'error_message' => null,
        ])->save();
    }

    private function markSkipped(Project $project, string $reason): void
    {
        $this->markSkippedByProjectId($reason, $project->id);
    }

    private function markSkippedByProjectId(string $reason, ?int $projectId = null): void
    {
        ProjectHealthExplanation::query()
            ->where('project_id', $projectId ?? $this->projectId)
            ->where('status', ProjectHealthExplanationStatus::Pending->value)
            ->update([
                'status' => ProjectHealthExplanationStatus::Skipped,
                'failed_at' => null,
                'error_message' => Str::limit($reason, 2_000, ''),
            ]);
    }
}
