<?php

namespace App\Http\Controllers;

use App\Domain\AiInsights\Jobs\GeneratePullRequestRiskAssessmentJob;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Models\GithubPullRequest;
use App\Models\PullRequestRiskAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PullRequestRiskRegenerationController extends Controller
{
    public function __invoke(Request $request, GithubPullRequest $pullRequest): RedirectResponse
    {
        $pullRequest->loadMissing('repository.project');

        $project = $pullRequest->repository?->project;

        abort_unless(
            $project !== null && $request->user()?->can('update', $project),
            403,
        );

        if (! config('services.llm.enabled', false)) {
            return back()->withErrors([
                'risk' => 'AI insights are disabled for this environment.',
            ]);
        }

        PullRequestRiskAssessment::query()->updateOrCreate(
            ['github_pull_request_id' => $pullRequest->id],
            [
                'status' => PullRequestRiskAssessmentStatus::Pending->value,
                'failed_at' => null,
                'error_message' => null,
            ],
        );

        GeneratePullRequestRiskAssessmentJob::dispatch($pullRequest->id);

        return back()->with('status', 'PR risk assessment queued.');
    }
}
