<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncRepositoryPullRequestsJob;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

/**
 * Manual "Run sync" button on the Repository PRs tab. Re-dispatches
 * `SyncRepositoryPullRequestsJob` for the given repository. Mirrors
 * spec 015's `RepositoryIssuesSyncController` exactly.
 *
 * Authorization: `ProjectPolicy::update` — only the project owner can
 * trigger a re-sync.
 */
class RepositoryPullRequestsSyncController extends Controller
{
    public function __invoke(Repository $repository): RedirectResponse
    {
        $repository->loadMissing('project');
        $this->authorize('update', $repository->project);

        SyncRepositoryPullRequestsJob::dispatch($repository->id);

        return back()->with('status', 'Pull requests sync queued.');
    }
}
