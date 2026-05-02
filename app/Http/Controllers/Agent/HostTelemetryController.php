<?php

namespace App\Http\Controllers\Agent;

use App\Domain\Docker\Actions\IngestHostTelemetryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\IngestTelemetryRequest;
use App\Models\Host;
use Illuminate\Http\Response;

/**
 * Receives `POST /agent/telemetry` (spec 027). Auth lives in
 * `AuthenticateAgent` middleware — we just pull the resolved host
 * back off the request, hand validated payload to the action, and
 * return 204.
 */
class HostTelemetryController extends Controller
{
    public function __invoke(
        IngestTelemetryRequest $request,
        IngestHostTelemetryAction $ingest,
    ): Response {
        /** @var Host $host */
        $host = $request->attributes->get('agent_host');

        $ingest->execute($host, $request->validated());

        return response()->noContent();
    }
}
