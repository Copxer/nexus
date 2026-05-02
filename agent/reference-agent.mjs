#!/usr/bin/env node
// @ts-check
/**
 * Nexus reference agent (spec 027).
 *
 * A minimal Node 20+ ESM script that gathers Docker host + container
 * telemetry from the local Docker daemon and POSTs it to a Nexus
 * `/agent/telemetry` endpoint on a fixed interval. Single file, no
 * dependencies — the goal is to *document* the payload contract, not
 * to be a production-grade agent.
 *
 * The production-grade Go agent (roadmap §8.7) will follow.
 *
 * Required env vars:
 *   NEXUS_URL           — base URL of the Nexus deployment.
 *   NEXUS_AGENT_TOKEN   — plaintext bearer minted in Settings → Hosts.
 *
 * Optional:
 *   NEXUS_AGENT_INTERVAL_SECONDS  — push interval (default 30).
 *   NEXUS_AGENT_DOCKER_BIN        — path to docker CLI (default
 *                                   `docker` from $PATH).
 */

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const env = {
    nexusUrl: requireEnv('NEXUS_URL'),
    token: requireEnv('NEXUS_AGENT_TOKEN'),
    intervalSeconds: positiveInt(
        process.env.NEXUS_AGENT_INTERVAL_SECONDS,
        30,
    ),
    dockerBin: process.env.NEXUS_AGENT_DOCKER_BIN ?? 'docker',
};

const endpoint = new URL('/agent/telemetry', env.nexusUrl).toString();

function requireEnv(name) {
    const v = process.env[name];
    if (!v) {
        console.error(`[nexus-agent] missing required env var: ${name}`);
        process.exit(2);
    }
    return v;
}

function positiveInt(value, fallback) {
    const n = Number.parseInt(value ?? '', 10);
    return Number.isFinite(n) && n > 0 ? n : fallback;
}

async function dockerJson(args) {
    const { stdout } = await execFileAsync(env.dockerBin, args, {
        maxBuffer: 32 * 1024 * 1024,
    });
    // `docker ... --format '{{json .}}'` emits one JSON object per
    // line. The single `docker info ... --format '{{json .}}'` form
    // emits one object total. Caller specifies which it expects.
    return stdout;
}

function parseLineJson(stdout) {
    return stdout
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => JSON.parse(line));
}

async function gatherHostFacts() {
    const out = await dockerJson(['info', '--format', '{{json .}}']);
    const info = JSON.parse(out);
    return {
        cpu_count: info.NCPU ?? null,
        memory_total_mb: info.MemTotal
            ? Math.round(info.MemTotal / 1024 / 1024)
            : null,
        os: info.OperatingSystem ?? null,
        docker_version: info.ServerVersion ?? null,
    };
}

function parseSize(value) {
    // docker stats reports memory like "12.34MiB / 128MiB". We extract
    // the leading number + unit and normalise to MB.
    if (!value || typeof value !== 'string') return null;
    const m = value.match(/([\d.]+)\s*([KMGT]?i?B)/i);
    if (!m) return null;
    const n = Number.parseFloat(m[1]);
    if (!Number.isFinite(n)) return null;
    const unit = m[2].toUpperCase();
    const factors = {
        B: 1 / 1024 / 1024,
        KB: 1 / 1024,
        KIB: 1 / 1024,
        MB: 1,
        MIB: 1,
        GB: 1024,
        GIB: 1024,
        TB: 1024 * 1024,
        TIB: 1024 * 1024,
    };
    const factor = factors[unit] ?? null;
    return factor === null ? null : Math.round(n * factor);
}

function parsePercent(value) {
    if (!value || typeof value !== 'string') return null;
    const m = value.match(/([\d.]+)/);
    if (!m) return null;
    const n = Number.parseFloat(m[1]);
    return Number.isFinite(n) ? n : null;
}

function parseRxTx(value) {
    if (!value || typeof value !== 'string') return [null, null];
    const [rx, tx] = value.split('/').map((s) => s.trim());
    return [bytesFromHumane(rx), bytesFromHumane(tx)];
}

function bytesFromHumane(value) {
    if (!value || typeof value !== 'string') return null;
    const m = value.match(/([\d.]+)\s*([KMGT]?i?B)?/i);
    if (!m) return null;
    const n = Number.parseFloat(m[1]);
    if (!Number.isFinite(n)) return null;
    const unit = (m[2] ?? 'B').toUpperCase();
    const factors = {
        B: 1,
        KB: 1024,
        KIB: 1024,
        MB: 1024 ** 2,
        MIB: 1024 ** 2,
        GB: 1024 ** 3,
        GIB: 1024 ** 3,
        TB: 1024 ** 4,
        TIB: 1024 ** 4,
    };
    const factor = factors[unit] ?? null;
    return factor === null ? null : Math.round(n * factor);
}

async function gatherHostMetrics() {
    // `docker info` doesn't return current CPU/memory utilisation —
    // it just gives capacity. For the reference agent we leave the
    // host-level utilisation null and let the per-container metrics
    // tell the story. A production agent would read /proc on Linux.
    return {
        cpu_percent: null,
        memory_used_mb: null,
        load_average: null,
        network_rx_bytes: null,
        network_tx_bytes: null,
    };
}

async function gatherContainers() {
    const psOut = await dockerJson([
        'ps',
        '-a',
        '--no-trunc',
        '--format',
        '{{json .}}',
    ]);
    const psRows = parseLineJson(psOut);

    const statsOut = await dockerJson([
        'stats',
        '--no-stream',
        '--no-trunc',
        '--format',
        '{{json .}}',
    ]);
    const statsRows = parseLineJson(statsOut);
    const statsById = new Map(statsRows.map((r) => [r.ID ?? r.Id, r]));

    return psRows.map((row) => {
        const id = row.ID ?? row.Id;
        const stats = statsById.get(id) ?? {};
        const [rxBytes, txBytes] = parseRxTx(stats.NetIO);
        const [readBytes, writeBytes] = parseRxTx(stats.BlockIO);
        const memUsage = parseSize((stats.MemUsage ?? '').split('/')[0]);
        const memLimit = parseSize(
            (stats.MemUsage ?? '').split('/')[1] ?? '',
        );
        const [imageBase, imageTag] = splitImage(row.Image);
        return {
            container_id: id,
            name: stripLeadingSlash(row.Names ?? ''),
            image: imageBase,
            image_tag: imageTag,
            status: row.State ?? null,
            state: row.State ?? null,
            health_status: parseHealth(row.Status),
            ports: row.Ports ? row.Ports.split(',').map((p) => p.trim()).filter(Boolean) : [],
            labels: parseLabels(row.Labels),
            metrics: {
                cpu_percent: parsePercent(stats.CPUPerc),
                memory_usage_mb: memUsage,
                memory_limit_mb: memLimit,
                network_rx_bytes: rxBytes,
                network_tx_bytes: txBytes,
                block_read_bytes: readBytes,
                block_write_bytes: writeBytes,
            },
        };
    });
}

function splitImage(image) {
    if (!image || typeof image !== 'string') return [image ?? '', null];
    const idx = image.lastIndexOf(':');
    if (idx === -1 || image.indexOf('/', idx) !== -1) return [image, null];
    return [image.slice(0, idx), image.slice(idx + 1)];
}

function stripLeadingSlash(name) {
    return name.startsWith('/') ? name.slice(1) : name;
}

function parseHealth(status) {
    if (!status || typeof status !== 'string') return null;
    if (/\(healthy\)/i.test(status)) return 'healthy';
    if (/\(unhealthy\)/i.test(status)) return 'unhealthy';
    if (/\(starting\)/i.test(status)) return 'starting';
    return null;
}

function parseLabels(value) {
    if (!value || typeof value !== 'string') return {};
    /** @type {Record<string, string>} */
    const out = {};
    value.split(',').forEach((kv) => {
        const idx = kv.indexOf('=');
        if (idx === -1) return;
        out[kv.slice(0, idx).trim()] = kv.slice(idx + 1).trim();
    });
    return out;
}

async function buildPayload() {
    const [facts, metrics, containers] = await Promise.all([
        gatherHostFacts(),
        gatherHostMetrics(),
        gatherContainers(),
    ]);

    return {
        recorded_at: new Date().toISOString(),
        host: { facts, metrics },
        containers,
    };
}

async function postOnce() {
    const payload = await buildPayload();
    const res = await fetch(endpoint, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${env.token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(payload),
    });

    if (res.ok) {
        console.log(
            `[nexus-agent] ${new Date().toISOString()} OK · ${payload.containers.length} container(s)`,
        );
        return { sleepSeconds: env.intervalSeconds };
    }

    if (res.status === 401) {
        console.error(
            '[nexus-agent] 401 Unauthorized — token revoked or wrong NEXUS_URL. Exiting.',
        );
        process.exit(3);
    }

    if (res.status === 429) {
        const retryAfter = positiveInt(res.headers.get('retry-after'), 60);
        console.warn(
            `[nexus-agent] 429 rate limited — sleeping ${retryAfter}s before retry.`,
        );
        return { sleepSeconds: retryAfter };
    }

    let body = '';
    try {
        body = (await res.text()).slice(0, 500);
    } catch {
        // ignore
    }
    console.warn(
        `[nexus-agent] ${res.status} ${res.statusText} — retrying next interval. body: ${body}`,
    );
    return { sleepSeconds: env.intervalSeconds };
}

function sleep(seconds) {
    return new Promise((resolve) => setTimeout(resolve, seconds * 1000));
}

async function main() {
    console.log(
        `[nexus-agent] starting · endpoint=${endpoint} interval=${env.intervalSeconds}s`,
    );
    // Eternal loop. The runtime supervisor (systemd, Docker restart
    // policy) handles agent crashes; we don't try to recover from
    // pathological errors ourselves.
    /* eslint-disable no-constant-condition */
    while (true) {
        let nextSleep = env.intervalSeconds;
        try {
            const result = await postOnce();
            nextSleep = result.sleepSeconds;
        } catch (err) {
            console.warn(
                `[nexus-agent] tick failed: ${err instanceof Error ? err.message : err}`,
            );
        }
        await sleep(nextSleep);
    }
}

main();
