<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Agent\HostTelemetryController;
use App\Http\Controllers\AlertAcknowledgeController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertMuteController;
use App\Http\Controllers\AlertResolveController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\GithubRepositoryImportController;
use App\Http\Controllers\Monitoring\AgentTokenController;
use App\Http\Controllers\Monitoring\HostController;
use App\Http\Controllers\Monitoring\WebsiteController;
use App\Http\Controllers\Monitoring\WebsiteProbeController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PaletteSearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicStatus\ConfirmSubscriptionController;
use App\Http\Controllers\PublicStatus\ShowController;
use App\Http\Controllers\PublicStatus\SubscribeController;
use App\Http\Controllers\PublicStatus\UnsubscribeController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\RepositoryIssuesSyncController;
use App\Http\Controllers\RepositoryPullRequestsSyncController;
use App\Http\Controllers\RepositorySyncAllController;
use App\Http\Controllers\RepositorySyncController;
use App\Http\Controllers\RepositoryWorkflowRunsSyncController;
use App\Http\Controllers\Settings\NotificationsController;
use App\Http\Controllers\Settings\RulesController;
use App\Http\Controllers\Settings\ThemeController;
use App\Http\Controllers\Settings\WebhookDeliveryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\WorkItemController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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

    // Spec 043 — command palette async search. Debounced client-side
    // (200ms); throttled server-side (30/min per §5 operator checklist).
    Route::get('/palette/search', PaletteSearchController::class)
        ->middleware('throttle:30,1')
        ->name('palette.search');

    // Spec 036 — per-user theme preference. The persisted value
    // surfaces via `auth.user.theme` (HandleInertiaRequests); the
    // `<html>` class toggle happens client-side in AppLayout.
    Route::post('/settings/theme', ThemeController::class)
        ->name('settings.theme.update');

    // Spec 037 — webhook delivery list + retry. Filter strip lives on
    // the index; retry is a single-row POST that re-dispatches the
    // job against the stored payload.
    Route::get('/settings/webhook-deliveries', WebhookDeliveryController::class)
        ->name('settings.webhook-deliveries.index');
    Route::post('/settings/webhook-deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])
        ->middleware('throttle:30,1') // spec 039
        ->name('settings.webhook-deliveries.retry');

    // Spec 042 — outbound alert notifications (email / Slack / webhook).
    // Single Inertia page carrying three logical tabs; the controller
    // fans out mutations across channels + preferences + deliveries.
    Route::get('/settings/notifications', [NotificationsController::class, 'index'])
        ->name('settings.notifications.index');
    Route::post('/settings/notifications/channels', [NotificationsController::class, 'storeChannel'])
        ->middleware('throttle:10,1')
        ->name('settings.notifications.channels.store');
    Route::patch('/settings/notifications/channels/{channel}', [NotificationsController::class, 'updateChannel'])
        ->middleware('throttle:20,1')
        ->name('settings.notifications.channels.update');
    Route::delete('/settings/notifications/channels/{channel}', [NotificationsController::class, 'destroyChannel'])
        ->middleware('throttle:20,1')
        ->name('settings.notifications.channels.destroy');
    Route::post('/settings/notifications/channels/{channel}/test', [NotificationsController::class, 'testChannel'])
        ->middleware('throttle:10,1')
        ->name('settings.notifications.channels.test');
    Route::post('/settings/notifications/preferences', [NotificationsController::class, 'storePreference'])
        ->middleware('throttle:20,1')
        ->name('settings.notifications.preferences.store');
    Route::patch('/settings/notifications/preferences/{preference}', [NotificationsController::class, 'updatePreference'])
        ->middleware('throttle:20,1')
        ->name('settings.notifications.preferences.update');
    Route::delete('/settings/notifications/preferences/{preference}', [NotificationsController::class, 'destroyPreference'])
        ->middleware('throttle:20,1')
        ->name('settings.notifications.preferences.destroy');
    Route::post('/settings/notifications/deliveries/{delivery}/retry', [NotificationsController::class, 'retryDelivery'])
        ->middleware('throttle:30,1')
        ->name('settings.notifications.deliveries.retry');

    // Spec 046 — user-tunable health-score weights + metric-driven
    // alert rules. Single Inertia page with two tabs; controller
    // handles both slices through per-tab endpoints.
    Route::get('/settings/rules', [RulesController::class, 'index'])
        ->name('settings.rules.index');
    Route::patch('/settings/rules/weights', [RulesController::class, 'updateWeights'])
        ->middleware('throttle:20,1')
        ->name('settings.rules.weights.update');
    Route::delete('/settings/rules/weights', [RulesController::class, 'resetWeights'])
        ->middleware('throttle:20,1')
        ->name('settings.rules.weights.reset');
    Route::post('/settings/rules', [RulesController::class, 'storeRule'])
        ->middleware('throttle:10,1')
        ->name('settings.rules.store');
    Route::patch('/settings/rules/{rule}', [RulesController::class, 'updateRule'])
        ->middleware('throttle:20,1')
        ->name('settings.rules.update');
    Route::delete('/settings/rules/{rule}', [RulesController::class, 'destroyRule'])
        ->middleware('throttle:20,1')
        ->name('settings.rules.destroy');

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
    // Spec 039 — `throttle:10,1` (10 syncs per minute per user)
    // stops a thumb-on-button spam from queueing thousands of jobs.
    // The 429 surfaces through Inertia's `errors` shared prop.
    Route::post('/repositories/{repository}/sync', RepositorySyncController::class)
        ->middleware('throttle:10,1')
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.sync');

    // Spec 015 — manual "Run sync" button on the Repository Issues tab.
    Route::post('/repositories/{repository}/issues/sync', RepositoryIssuesSyncController::class)
        ->middleware('throttle:10,1')
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.issues.sync');

    // Spec 016 — manual "Run sync" button on the Repository PRs tab.
    Route::post('/repositories/{repository}/pulls/sync', RepositoryPullRequestsSyncController::class)
        ->middleware('throttle:10,1')
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.pulls.sync');

    // Spec 020 — manual "Run sync" button on the Repository Workflow Runs tab.
    Route::post('/repositories/{repository}/workflow-runs/sync', RepositoryWorkflowRunsSyncController::class)
        ->middleware('throttle:10,1')
        ->where('repository', '[\w.-]+/[\w.-]+')
        ->name('repositories.workflow-runs.sync');

    // Global "Run sync" — the Cmd+K command-palette action. Bulk sibling
    // of the per-repo sync above; re-syncs every repo under the user's
    // projects. Literal path, so it never collides with the slash-keyed
    // `{repository}` routes. Spec 039 — tighter 2/min limit because
    // a single call fans out across every repo.
    Route::post('/repositories/sync-all', RepositorySyncAllController::class)
        ->middleware('throttle:2,1')
        ->name('repositories.sync-all');

    // Spec 016 — unified Work Items queue (issues + PRs).
    Route::get('/work-items', WorkItemController::class)->name('work-items.index');

    // Spec 018 — dedicated activity feed page. Right-rail shares the
    // same data via the activity.recent Inertia prop registered in
    // HandleInertiaRequests::share(); this page just hosts the wider
    // 100-event view + future filters.
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');

    // Spec 021 — cross-repo deployment timeline (workflow runs).
    Route::get('/deployments', [DeploymentController::class, 'index'])
        ->name('deployments.index');

    // Spec 023 — website monitoring CRUD + manual probe.
    // Nested under /monitoring/* so phase-6 hosts can sit beside it.
    Route::resource('monitoring/websites', WebsiteController::class)
        ->parameters(['websites' => 'website'])
        ->names('monitoring.websites');
    Route::post('/monitoring/websites/{website}/probe', WebsiteProbeController::class)
        ->middleware('throttle:20,1') // spec 039
        ->name('monitoring.websites.probe');

    // Spec 026 — Docker hosts CRUD + agent token lifecycle. Telemetry
    // ingestion arrives in spec 027; the UI metric rendering in 028.
    Route::resource('monitoring/hosts', HostController::class)
        ->parameters(['hosts' => 'host'])
        ->names('monitoring.hosts');
    Route::post('/monitoring/hosts/{host}/tokens', [AgentTokenController::class, 'store'])
        ->name('monitoring.hosts.tokens.store');
    Route::post('/monitoring/hosts/{host}/tokens/{token}/rotate', [AgentTokenController::class, 'rotate'])
        ->name('monitoring.hosts.tokens.rotate');
    Route::delete('/monitoring/hosts/{host}/tokens/{token}', [AgentTokenController::class, 'destroy'])
        ->name('monitoring.hosts.tokens.destroy');

    // Spec 031 — Alerts UI. Index + three single-action lifecycle
    // verbs (ack / resolve / mute). Trigger / auto-resolve already
    // happen inside the existing transition emitters (spec 030).
    Route::get('/alerts', [AlertController::class, 'index'])
        ->name('alerts.index');
    Route::post('/alerts/{alert}/acknowledge', AlertAcknowledgeController::class)
        ->name('alerts.acknowledge');
    Route::post('/alerts/{alert}/resolve', AlertResolveController::class)
        ->name('alerts.resolve');
    Route::post('/alerts/{alert}/mute', AlertMuteController::class)
        ->name('alerts.mute');

    // Spec 034 — Analytics dashboard. Single-action invokable
    // controller. The `?range=7d|30d|90d` filter is read inside the
    // controller; queries scope strictly by the authenticated user's
    // owned projects so the page never leaks cross-tenant data.
    Route::get('/analytics', AnalyticsController::class)
        ->name('analytics.index');
});

// Spec 017 — GitHub webhooks (no auth/CSRF; signature-verified inside).
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->name('webhooks.github');

// Spec 027 — agent telemetry ingestion. Bearer auth + per-token rate
// limit are both handled inside `AuthenticateAgent` (alias `agent.auth`).
//
// `withoutMiddleware` strips the session/cookie/Inertia stack from the
// `web` group: agents are non-browser JSON clients, so we don't want
// every 30-second heartbeat from every host to spawn an orphan session
// row, set a Set-Cookie header, or run Inertia's prop-sharing closures.
// CSRF is already excluded for this path in bootstrap/app.php.
Route::post('/agent/telemetry', HostTelemetryController::class)
    ->middleware('agent.auth')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
        // Skip the CSRF middleware too — the path is already in the
        // except list (`bootstrap/app.php`), but the middleware still
        // refreshes the XSRF cookie at response time, which calls
        // `$request->session()` and explodes when StartSession isn't
        // running.
        PreventRequestForgery::class,
    ])
    ->name('agent.telemetry');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Spec 047 — public status page + subscribers. Guest-safe;
// throttled per §5 of the operator checklist. Placed at the bottom so
// the auth-scoped `/settings/*` routes above shadow any accidental
// name collision.
Route::get('/status/{project:slug}', ShowController::class)
    ->middleware('throttle:120,1')
    ->name('public-status.show');
Route::post('/status/{project:slug}/subscribe', SubscribeController::class)
    ->middleware('throttle:20,1')
    ->name('public-status.subscribe');
Route::get('/status/{project:slug}/confirm/{token}', ConfirmSubscriptionController::class)
    ->middleware('throttle:60,1')
    ->name('public-status.confirm');
Route::get('/status/subscribers/unsubscribe/{token}', UnsubscribeController::class)
    ->middleware('throttle:60,1')
    ->name('public-status.unsubscribe');

require __DIR__.'/auth.php';
