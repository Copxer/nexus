<?php

namespace App\Domain\Analytics\Queries;

use App\Enums\WorkflowRunConclusion;
use App\Enums\WorkflowRunStatus;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deployment frequency + success rate metrics for `/analytics` (spec
 * 034). Both slices are derived from `workflow_runs` joined through
 * `repositories.project_id` → `projects.owner_user_id` so the page
 * sees only the authenticated user's data.
 *
 * "Deployment" here is a completed `workflow_runs` row. Cancelled /
 * skipped / queued runs don't count toward either metric — they're
 * neither a successful nor a failed deployment. Success rate is
 * `success / (success + failure)` so the chart treats those terminal
 * conclusions only.
 */
class GetDeploymentMetricsQuery
{
    /**
     * @return array{
     *     frequency: array{total: int, sparkline: array<int, int>},
     *     success_rate: array{percent: float|null, status: 'success'|'warning'|'danger'|'muted'},
     * }
     */
    public function execute(User $user, Carbon $from): array
    {
        $days = $this->dayCount($from);
        $now = now();

        $base = WorkflowRun::query()
            ->join('repositories', 'workflow_runs.repository_id', '=', 'repositories.id')
            ->join('projects', 'repositories.project_id', '=', 'projects.id')
            ->where('projects.owner_user_id', $user->id)
            ->where('workflow_runs.status', WorkflowRunStatus::Completed->value)
            ->where('workflow_runs.run_completed_at', '>=', $from)
            ->where('workflow_runs.run_completed_at', '<', $now);

        $total = (clone $base)->count('workflow_runs.id');

        $successful = (clone $base)
            ->where('workflow_runs.conclusion', WorkflowRunConclusion::Success->value)
            ->count('workflow_runs.id');

        $failed = (clone $base)
            ->where('workflow_runs.conclusion', WorkflowRunConclusion::Failure->value)
            ->count('workflow_runs.id');

        $successPlusFail = $successful + $failed;
        $successPercent = $successPlusFail > 0
            ? round(($successful / $successPlusFail) * 100, 2)
            : null;

        /** @var Collection<int, object{date: string, total: int}> $rows */
        $rows = (clone $base)
            ->selectRaw('DATE(workflow_runs.run_completed_at) as date, COUNT(workflow_runs.id) as total')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $sparkline = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $row = $rows->get($day);
            $sparkline[] = $row === null ? 0 : (int) $row->total;
        }

        return [
            'frequency' => [
                'total' => $total,
                'sparkline' => $sparkline,
            ],
            'success_rate' => [
                'percent' => $successPercent,
                'status' => $this->statusFor($successPercent),
            ],
        ];
    }

    private function dayCount(Carbon $from): int
    {
        return (int) $from->diffInDays(now()->startOfDay()) + 1;
    }

    /** @return 'success'|'warning'|'danger'|'muted' */
    private function statusFor(?float $percent): string
    {
        return match (true) {
            $percent === null => 'muted',
            $percent >= 95.0 => 'success',
            $percent >= 85.0 => 'warning',
            default => 'danger',
        };
    }
}
