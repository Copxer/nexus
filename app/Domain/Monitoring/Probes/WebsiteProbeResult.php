<?php

namespace App\Domain\Monitoring\Probes;

use App\Enums\WebsiteCheckStatus;

/**
 * Immutable result of a single `RunWebsiteProbeAction` invocation.
 *
 * Carries everything `RecordWebsiteCheckAction` needs to persist a
 * `WebsiteCheck` row + update the parent `Website.last_*` fields.
 * No DB access here — pure data.
 *
 * `httpStatusCode` and `responseTimeMs` are nullable because a
 * transport-level failure (DNS / timeout / connection refused / TLS)
 * never produces them.
 */
final class WebsiteProbeResult
{
    public function __construct(
        public readonly WebsiteCheckStatus $status,
        public readonly ?int $httpStatusCode,
        public readonly ?int $responseTimeMs,
        public readonly ?string $errorMessage,
    ) {}
}
