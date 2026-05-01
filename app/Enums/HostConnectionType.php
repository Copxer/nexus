<?php

namespace App\Enums;

/**
 * How Nexus reaches (or is reached by) a host.
 *
 * Phase 6 only ships the `agent` strategy — a small process running on
 * the host pushes telemetry to `/agent/telemetry` (spec 027). The other
 * cases exist as data so a host can be migrated to a different strategy
 * without a column rename later (roadmap §6.5).
 *
 * - `agent`      — push from a Nexus agent on the host (Phase 6 MVP).
 * - `ssh`        — pull over SSH (future).
 * - `docker_api` — pull from Docker Engine API (future).
 * - `manual`     — no automatic telemetry; the host is tracked for
 *                  inventory only.
 */
enum HostConnectionType: string
{
    case Agent = 'agent';
    case Ssh = 'ssh';
    case DockerApi = 'docker_api';
    case Manual = 'manual';
}
