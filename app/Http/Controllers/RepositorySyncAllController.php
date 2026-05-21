<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Enums\RepositorySyncStatus;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Global "Run sync" — the Cmd+K command-palette action. Re-dispatches
 * `SyncGitHubRepositoryJob` for every repository under the current
 * user's projects; the bulk sibling of the per-repo
 * `RepositorySyncController`.
 *
 * The repo set is scoped to the user's own projects, so ownership is
 * enforced by the query itself — there is no foreign repository a
 * request could reach, hence no per-row policy check. Sync fields are
 * flipped to `syncing` in one bulk update before the jobs queue, so the
 * next render paints every affected row mid-sync.
 */
class RepositorySyncAllController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $repositories = Repository::query()
            ->whereHas('project', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->get();

        if ($repositories->isEmpty()) {
            return back()->with('status', 'No repositories to sync.');
        }

        // Clear the metadata and child-sync errors alongside the status
        // flip — the metadata job cascades into issues + PRs + workflow
        // runs, so the user's mental model is "Run sync re-runs all four".
        Repository::query()
            ->whereKey($repositories->modelKeys())
            ->update([
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

        foreach ($repositories as $repository) {
            SyncGitHubRepositoryJob::dispatch($repository->id);
        }

        $count = $repositories->count();

        return back()->with('status', "Queued sync for {$count} ".Str::plural('repository', $count).'.');
    }
}
