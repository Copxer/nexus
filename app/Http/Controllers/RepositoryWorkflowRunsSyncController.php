<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncRepositoryWorkflowRunsJob;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Run sync" button on the Repository Workflow Runs tab.
 * Re-dispatches `SyncRepositoryWorkflowRunsJob` for the given
 * repository. The auto-chain from spec 014's import flow still fires
 * (extended by spec 020 to include workflow runs); this is the
 * explicit path a user can use to refresh on demand.
 *
 * Authorization: `ProjectPolicy::update` — only the project owner can
 * trigger a re-sync, mirroring spec 015 / 016.
 */
class RepositoryWorkflowRunsSyncController extends Controller
{
    public function __invoke(Repository $repository): RedirectResponse
    {
        $repository->loadMissing('project');
        $this->authorize('update', $repository->project);

        SyncRepositoryWorkflowRunsJob::dispatch($repository->id);

        return back()->with('status', 'Workflow runs sync queued.');
    }
}
