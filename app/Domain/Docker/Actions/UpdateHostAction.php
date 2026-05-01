<?php

namespace App\Domain\Docker\Actions;

use App\Models\Host;

class UpdateHostAction
{
    /**
     * Updates only the user-editable fields. Telemetry-derived columns
     * (status, last_seen_at, cpu_count, memory_total_mb, etc.) are
     * deliberately excluded — they're owned by the ingestion path
     * (spec 027).
     *
     * @param  array{
     *     name?: string,
     *     provider?: ?string,
     *     endpoint_url?: ?string,
     * }  $attributes
     */
    public function execute(Host $host, array $attributes): Host
    {
        $host->fill(array_intersect_key($attributes, array_flip([
            'name',
            'provider',
            'endpoint_url',
        ])));

        $host->save();

        return $host;
    }
}
