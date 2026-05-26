<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Heartbeat timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | A host whose `last_seen_at` is older than this is flipped from
    | `online` to `offline` by `DetectOfflineHostsJob` (spec 029). Default
    | is 120 seconds — 4× the reference agent's default 30 s tick — tight
    | enough to catch silent failures within ~2 minutes, loose enough to
    | ride out a single missed tick + network blip.
    |
    */

    'heartbeat_timeout_seconds' => (int) env('HOSTS_HEARTBEAT_TIMEOUT_SECONDS', 120),

];
