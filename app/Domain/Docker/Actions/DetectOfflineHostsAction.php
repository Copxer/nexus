<?php

namespace App\Domain\Docker\Actions;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Enums\HostStatus;
use App\Models\Host;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 029 — scheduled offline detector. Every minute the job that
 * wraps this finds hosts in `status: online` whose `last_seen_at` is
 * older than `config('hosts.heartbeat_timeout_seconds')`, flips each
 * to `offline`, and emits one `host.offline` activity event.
 *
 * Pending and archived hosts are skipped — they were never online so
 * there is no transition to record. A late-arriving telemetry tick
 * after this flip flows back through `IngestHostTelemetryAction`,
 * which flips the row to `online` and emits `host.recovered`.
 *
 * Each row's status flip + activity event share a transaction so a
 * partial failure can't leave a host marked offline without the
 * corresponding rail entry (or vice versa).
 */
class DetectOfflineHostsAction
{
    public function __construct(
        private readonly CreateActivityEventAction $createActivity,
    ) {}

    /**
     * @return int Number of hosts flipped on this run (useful for the
     *             wrapping job's log line + the action's tests).
     */
    public function execute(): int
    {
        $threshold = (int) config('hosts.heartbeat_timeout_seconds', 120);
        $cutoff = Carbon::now()->subSeconds($threshold);

        $stale = Host::query()
            ->where('status', HostStatus::Online->value)
            ->where('last_seen_at', '<', $cutoff)
            ->get();

        $flipped = 0;

        foreach ($stale as $host) {
            DB::transaction(function () use ($host, $threshold): void {
                $lastSeenAt = $host->last_seen_at;

                $host->forceFill(['status' => HostStatus::Offline->value])->save();

                $this->createActivity->execute([
                    'event_type' => 'host.offline',
                    'severity' => ActivitySeverity::Danger,
                    'title' => "{$host->name} went offline",
                    'description' => "No telemetry in {$threshold}s",
                    'occurred_at' => Carbon::now(),
                    'source' => 'hosts',
                    'metadata' => [
                        'host_id' => $host->id,
                        'last_seen_at' => $lastSeenAt?->toIso8601String(),
                        'threshold_seconds' => $threshold,
                    ],
                ]);
            });

            $flipped++;
        }

        return $flipped;
    }
}
