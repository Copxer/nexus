<?php

namespace App\Domain\GitHub\Actions;

use App\Domain\GitHub\Jobs\SyncGitHubRepositoryJob;
use App\Domain\Repositories\Actions\LinkRepositoryToProjectAction;
use App\Models\Project;
use App\Models\Repository;

/**
 * Import a GitHub repository into a Nexus project. Reuses spec 011's
 * `LinkRepositoryToProjectAction` to create (or find) the local row in
 * `pending` state, then dispatches `SyncGitHubRepositoryJob` to fetch
 * the real metadata from GitHub.
 *
 * Idempotent: re-importing an already-linked repo is a no-op for the
 * row but a fresh sync is dispatched (the user is asking for "refresh
 * me", which is the same dispatch path as a first-time import).
 *
 * Authorization happens at the controller layer (ProjectPolicy::update).
 * This action assumes the caller has already cleared that gate.
 */
class ImportRepositoryAction
{
    public function __construct(
        private readonly LinkRepositoryToProjectAction $linker,
    ) {}

    public function execute(Project $project, string $input): Repository
    {
        $repository = $this->linker->execute($project, $input);

        SyncGitHubRepositoryJob::dispatch($repository->id);

        return $repository;
    }
}
