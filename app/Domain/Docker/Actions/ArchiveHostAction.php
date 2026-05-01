<?php

namespace App\Domain\Docker\Actions;

use App\Enums\HostStatus;
use App\Models\Host;
use Illuminate\Support\Facades\DB;

/**
 * Soft-archive: mark the host as archived, freeze its status, and
 * revoke every active agent token so a stale agent on a decommissioned
 * box can't keep ingesting. Telemetry history is kept around so the
 * host can show up in historical reports later.
 */
class ArchiveHostAction
{
    public function execute(Host $host): Host
    {
        return DB::transaction(function () use ($host): Host {
            $host->forceFill([
                'status' => HostStatus::Archived->value,
                'archived_at' => now(),
            ])->save();

            $host->agentTokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $host;
        });
    }
}
