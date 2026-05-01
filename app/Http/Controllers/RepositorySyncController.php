<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Enums\RepositorySyncStatus;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Run sync" button on the Repository show page header. Re-dispatches
 * `SyncGitHubRepositoryJob` for the given repository, which refreshes the
 * metadata (default branch, language, stars, push timestamps) and cascades
 * into `SyncRepositoryIssuesJob` + `SyncRepositoryPullRequestsJob` on success.
 *
 * Mirrors the per-tab `RepositoryIssuesSyncController` /
 * `RepositoryPullRequestsSyncController` shape introduced in specs 015 + 016
 * — the button is the parent-level sibling of those two.
 *
 * Flips `sync_status` to `syncing` synchronously before queuing the job. The
 * `back()` re-render therefore re-fetches the row and the show-page button
 * paints disabled + spinner immediately, debouncing rapid double-clicks
 * without needing `ShouldBeUnique` on the job.
 *
 * Authorization: `ProjectPolicy::update` — only the project owner can
 * trigger a re-sync, matching the existing tab-level controllers.
 */
class RepositorySyncController extends Controller
{
    public function __invoke(Repository $repository): RedirectResponse
    {
        $repository->loadMissing('project');
        $this->authorize('update', $repository->project);

        // Clear the metadata error and the child-sync errors as well —
        // the metadata job chains issues + PRs + workflow runs syncs on
        // success, so the user's mental model is "Run sync re-runs all
        // four." If we cleared only the metadata error, the page would
        // briefly show "Syncing…" at the top while stale red error
        // alerts on the Issues / PRs / Workflow Runs tabs persisted
        // until those child jobs picked up.
        $repository->update([
            'sync_status' => RepositorySyncStatus::Syncing->value,
            'sync_error' => null,
            'sync_failed_at' => null,
            'issues_sync_error' => null,
            'issues_sync_failed_at' => null,
            'prs_sync_error' => null,
            'prs_sync_failed_at' => null,
            'workflow_runs_sync_error' => null,
            'workflow_runs_sync_failed_at' => null,
        ]);

        SyncGitHubRepositoryJob::dispatch($repository->id);

        return back()->with('status', 'Repository sync queued.');
    }
}
