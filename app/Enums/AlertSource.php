<?php

namespace App\Enums;

/**
 * Origin domain of an Alert (spec 030). Matches roadmap §8.12.
 *
 * Spec 030 only emits `website` / `docker` / `deployment`; `github` is
 * reserved for repo/PR-scoped alerts (stale PR, PR risk, merge conflict);
 * `manual` is the user-pressed-the-button path; `system` is for Nexus's
 * own self-checks.
 *
 * `source_id` points into the corresponding domain table:
 *   website     → websites.id
 *   docker      → hosts.id
 *   deployment  → workflow_runs.id
 *   github      → repositories.id or github_pull_requests.id, depending on type
 *   manual      → null
 *   system      → null
 */
enum AlertSource: string
{
    case Website = 'website';
    case Docker = 'docker';
    case Deployment = 'deployment';
    case Github = 'github';
    case Manual = 'manual';
    case System = 'system';
}
