<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncRepositoryIssuesJob;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Run sync" button on the Repository Issues tab. Re-dispatches
 * `SyncRepositoryIssuesJob` for the given repository. The auto-chain
 * from spec 014's import flow still fires; this is the explicit path
 * a user can use to refresh on demand.
 *
 * Authorization: `ProjectPolicy::update` — only the project owner can
 * trigger a re-sync, mirroring spec 014's import controller policy.
 */
class RepositoryIssuesSyncController extends Controller
{
    public function __invoke(Repository $repository): RedirectResponse
    {
        $repository->loadMissing('project');
        $this->authorize('update', $repository->project);

        SyncRepositoryIssuesJob::dispatch($repository->id);

        return back()->with('status', 'Issues sync queued.');
    }
}
