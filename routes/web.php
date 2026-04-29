<?php

use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SettingsController;
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
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
