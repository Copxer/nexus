<?php

namespace App\Http\Controllers;

use App\Domain\AiInsights\Jobs\GenerateProjectHealthExplanationJob;
use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectHealthExplanationRegenerationController extends Controller
{
    public function __invoke(Request $request, Project $project): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $project), 403);

        if (! config('services.llm.enabled', false)) {
            return back()->withErrors([
                'health_explanation' => 'AI insights are disabled for this environment.',
            ]);
        }

        ProjectHealthExplanation::query()->updateOrCreate(
            ['project_id' => $project->id],
            [
                'status' => ProjectHealthExplanationStatus::Pending->value,
                'health_score' => $project->health_score ?? 0,
                'health_band' => HealthScoreBand::fromScore($project->health_score ?? 0)->value,
                'failed_at' => null,
                'error_message' => null,
            ],
        );

        GenerateProjectHealthExplanationJob::dispatch($project->id);

        return back()->with('status', 'Project health explanation queued.');
    }
}
