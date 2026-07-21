<?php

namespace App\Domain\AiInsights\Jobs;

use App\Domain\AiInsights\Actions\GeneratePullRequestRiskAssessmentAction;
use App\Domain\AiInsights\Queries\GetPullRequestRiskInputQuery;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Models\GithubPullRequest;
use App\Models\PullRequestRiskAssessment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class GeneratePullRequestRiskAssessmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $pullRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->pullRequestId;
    }

    public function handle(GetPullRequestRiskInputQuery $inputQuery, GeneratePullRequestRiskAssessmentAction $generate): void
    {
        if (! config('services.llm.enabled', false)) {
            $this->markSkippedByPullRequestId('AI features are disabled.');

            return;
        }

        $pullRequest = GithubPullRequest::query()
            ->with('repository.project')
            ->find($this->pullRequestId);

        $ownerId = $pullRequest?->repository?->project?->owner_user_id;
        $owner = $ownerId === null ? null : User::query()->find($ownerId);

        if ($pullRequest === null || $owner === null) {
            return;
        }

        $this->markPending($pullRequest);

        $snapshot = $inputQuery->execute($owner, $pullRequest);

        if ($snapshot === null) {
            $this->markSkipped($pullRequest, 'Pull request is no longer available in the scoped AI input query.');

            return;
        }

        $generate->execute($pullRequest, $snapshot);
    }

    public function failed(Throwable $exception): void
    {
        $assessment = PullRequestRiskAssessment::query()
            ->where('github_pull_request_id', $this->pullRequestId)
            ->where('status', PullRequestRiskAssessmentStatus::Pending->value)
            ->first();

        if ($assessment === null) {
            return;
        }

        $assessment->forceFill([
            'status' => PullRequestRiskAssessmentStatus::Failed,
            'failed_at' => now(),
            'error_message' => Str::limit($exception->getMessage(), 2_000, ''),
        ])->save();
    }

    private function markPending(GithubPullRequest $pullRequest): void
    {
        $assessment = PullRequestRiskAssessment::query()
            ->where('github_pull_request_id', $pullRequest->id)
            ->first() ?? new PullRequestRiskAssessment(['github_pull_request_id' => $pullRequest->id]);

        $assessment->forceFill([
            'status' => PullRequestRiskAssessmentStatus::Pending,
            'failed_at' => null,
            'error_message' => null,
        ])->save();
    }

    private function markSkipped(GithubPullRequest $pullRequest, string $reason): void
    {
        $this->markSkippedByPullRequestId($reason, $pullRequest->id);
    }

    private function markSkippedByPullRequestId(string $reason, ?int $pullRequestId = null): void
    {
        PullRequestRiskAssessment::query()
            ->where('github_pull_request_id', $pullRequestId ?? $this->pullRequestId)
            ->where('status', PullRequestRiskAssessmentStatus::Pending->value)
            ->update([
                'status' => PullRequestRiskAssessmentStatus::Skipped,
                'failed_at' => null,
                'error_message' => Str::limit($reason, 2_000, ''),
            ]);
    }
}
