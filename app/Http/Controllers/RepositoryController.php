<?php

namespace App\Http\Controllers;

use App\Domain\Repositories\Actions\LinkRepositoryToProjectAction;
use App\Http\Requests\Repositories\LinkRepositoryRequest;
use App\Models\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class RepositoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Repository::class);

        $repositories = Repository::query()
            ->with('project:id,slug,name,color,icon,owner_user_id')
            ->latest('last_pushed_at')
            ->latest()
            ->get()
            ->map(fn (Repository $repo) => $this->transform($repo));

        return Inertia::render('Repositories/Index', [
            'repositories' => $repositories,
        ]);
    }

    public function show(Request $request, Repository $repository): Response
    {
        $this->authorize('view', $repository);

        $repository->loadMissing('project:id,slug,name,color,icon,owner_user_id');

        return Inertia::render('Repositories/Show', [
            'repository' => $this->transform($repository),
            'canDelete' => $request->user()?->can('delete', $repository) ?? false,
        ]);
    }

    public function store(LinkRepositoryRequest $request, LinkRepositoryToProjectAction $action): RedirectResponse
    {
        $project = $request->resolvedProject();

        // The form request already authorized this, but we re-resolve via
        // policy so a stale prop doesn't slip past.
        $this->authorize('create', [Repository::class, $project]);

        try {
            $action->execute($project, $request->string('repository')->toString());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['repository' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'repository' => 'That repository is already linked to another project.',
            ]);
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Repository linked.');
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        $this->authorize('delete', $repository);

        $project = $repository->project;
        $repository->delete();

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Repository unlinked.');
    }

    /**
     * Project shape returned to the page. Centralised so Index/Show and
     * the Project Show Repositories tab read the same fields.
     */
    private function transform(Repository $repository): array
    {
        return [
            'id' => $repository->id,
            'owner' => $repository->owner,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'html_url' => $repository->html_url,
            'default_branch' => $repository->default_branch,
            'visibility' => $repository->visibility,
            'language' => $repository->language,
            'description' => $repository->description,
            'stars_count' => $repository->stars_count,
            'forks_count' => $repository->forks_count,
            'open_issues_count' => $repository->open_issues_count,
            'open_prs_count' => $repository->open_prs_count,
            'last_pushed_at' => $repository->last_pushed_at?->diffForHumans(),
            'last_synced_at' => $repository->last_synced_at?->diffForHumans(),
            'sync_status' => $repository->sync_status?->value,
            'project' => $repository->project ? [
                'id' => $repository->project->id,
                'slug' => $repository->project->slug,
                'name' => $repository->project->name,
                'color' => $repository->project->color,
                'icon' => $repository->project->icon,
            ] : null,
        ];
    }
}
