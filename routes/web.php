<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\GithubRepositoryImportController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\RepositoryIssuesSyncController;
use App\Http\Controllers\RepositoryPullRequestsSyncController;
use App\Http\Controllers\RepositorySyncController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\WorkItemController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/overview', OverviewController::class)
    ->middleware(['auth', 'verified'])
    ->name('overview');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('projects', ProjectController::class);

    // Two-segment route key (`owner/name`) — the regex `where()` lets
    // Laravel's binder accept the slash inside a single route param.
    Route::resource('repositories', RepositoryController::class)
        ->only(['index', 'show', 'store', 'destroy'])
        ->where(['repository' => '[\w.-]+/[\w.-]+']);

    Route::get('/settings', SettingsController::class)->name('settings.index');

    Route::get('/integrations/github/connect', [GithubConnectionController::class, 'redirect'])
        ->name('integrations.github.connect');
    Route::get('/integrations/github/callback', [GithubConnectionController::class, 'callback'])
        ->name('integrations.github.callback');
    Route::delete('/integrations/github', [GithubConnectionController::class, 'destroy'])
        ->name('integrations.github.disconnect');

    // Project-scoped GitHub repository import flow (spec 014).
    Route::get('/projects/{project}/repositories/import', [GithubRepositoryImportController::class, 'index'])
        ->name('projects.repositories.import.index');
    Route::post('/projects/{project}/repositories/import', [GithubRepositoryImportController::class, 'store'])
        ->name('projects.repositories.import.store');

    // Manual "Run sync" button on the Repository show-page header.
    // Re-dispatches the parent SyncGitHubRepositoryJob, which refreshes
    // metadata (default branch, language, stars, push timestamps) and
    // cascades into the issues + PR sync jobs below.
    Route::post('/repositories/{repository}/sync', RepositorySyncController::class)
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.sync');

    // Spec 015 — manual "Run sync" button on the Repository Issues tab.
    Route::post('/repositories/{repository}/issues/sync', RepositoryIssuesSyncController::class)
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.issues.sync');

    // Spec 016 — manual "Run sync" button on the Repository PRs tab.
    Route::post('/repositories/{repository}/pulls/sync', RepositoryPullRequestsSyncController::class)
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.pulls.sync');

    // Spec 016 — unified Work Items queue (issues + PRs).
    Route::get('/work-items', WorkItemController::class)->name('work-items.index');

    // Spec 018 — dedicated activity feed page. Right-rail shares the
    // same data via the activity.recent Inertia prop registered in
    // HandleInertiaRequests::share(); this page just hosts the wider
    // 100-event view + future filters.
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
});

// Spec 017 — GitHub webhooks (no auth/CSRF; signature-verified inside).
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->name('webhooks.github');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
