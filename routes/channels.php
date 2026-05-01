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
