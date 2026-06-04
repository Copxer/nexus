<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Spec 019 — per-user activity feed channel. `ActivityEventCreated`
// broadcasts here when a webhook (or any future origin funnelling
// through `CreateActivityEventAction`) lands. Authorization mirrors
// the user-model channel: only the matching user can subscribe.
Broadcast::channel('users.{userId}.activity', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Spec 021 — per-user deployments channel. `WorkflowRunUpserted`
// broadcasts here when the GitHub webhook handler upserts a workflow
// run. The Vue page partial-reloads on receipt; bulk REST sync does
// NOT broadcast (would flood the channel on backfill).
Broadcast::channel('users.{userId}.deployments', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Spec 025 — per-user monitoring channel. `WebsiteCheckRecorded`
// broadcasts here on every persisted check so the Show page reflects
// realtime probe results. Spec 024's transition events still ride
// the activity channel above; this is the per-check pulse on top of
// that, scoped to the monitoring page only.
Broadcast::channel('users.{userId}.monitoring', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Spec 028 — per-user hosts channel. `HostTelemetryRecorded`
// broadcasts here on every agent telemetry tick so the Host Show
// page reflects fresh CPU / memory / container stats without a
// manual refresh. Mirrors the monitoring channel above.
Broadcast::channel('users.{userId}.hosts', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Spec 032 — per-user alerts channel. `AlertTriggered` /
// `AlertResolved` broadcast here on every fresh trigger / resolve so
// the `/alerts` page + the TopBar bell react in realtime. Separate
// from `users.{id}.activity` (which already carries the broader
// `alert.triggered` activity event) so the targeted surfaces don't
// need to filter every other event type out of the rail stream.
Broadcast::channel('users.{userId}.alerts', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Spec 033 — per-user dashboard channel. `HealthScoreUpdated`
// broadcasts here whenever a project's score changes (transition-
// driven or scheduled-sweep) so Overview refreshes its score chips
// without a manual reload. 035 will reuse the channel for the
// activity-heatmap real-data pulse and the Overview risky-projects
// re-rank.
Broadcast::channel('users.{userId}.dashboard', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
