# Nexus reference agent

Minimal Node 20+ ESM script that pushes Docker host + container telemetry to a Nexus deployment. Single file, no dependencies — its job is to **document the payload contract** for the production-grade agent that will follow (roadmap §8.7).

## Quick start

```bash
# 1. In Nexus: Settings → Hosts → New host → mint an agent token.
#    Copy the plaintext immediately — it's shown only once.

# 2. On the host, with Docker installed and `docker info` working:
NEXUS_URL=https://nexus.example.com \
NEXUS_AGENT_TOKEN=paste-your-token-here \
node agent/reference-agent.mjs
```

You should see one log line per push:

```
[nexus-agent] 2026-05-01T19:55:30.123Z OK · 12 container(s)
```

In Nexus, the host's status flips to **online** within one interval, and the host detail page starts accumulating snapshots.

## Environment variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `NEXUS_URL` | yes | — | Base URL of the Nexus deployment (e.g. `https://nexus.example.com`). |
| `NEXUS_AGENT_TOKEN` | yes | — | Plaintext bearer token from Settings → Hosts → "Mint agent token". |
| `NEXUS_AGENT_INTERVAL_SECONDS` | no | `30` | Seconds between pushes. Capped server-side at 60 req/min/token. |
| `NEXUS_AGENT_DOCKER_BIN` | no | `docker` (from `$PATH`) | Override path to the `docker` CLI. |

## Exit codes

| Code | Meaning |
|---|---|
| `0` | clean shutdown (not currently triggered — script runs forever) |
| `2` | missing required env var at startup |
| `3` | server returned 401 (token revoked or wrong `NEXUS_URL`) |

Anything else (network blips, 5xx, parse errors) is logged and retried on the next interval. The supervisor (systemd, Docker restart policy) handles process crashes.

## Sample systemd unit

```ini
# /etc/systemd/system/nexus-agent.service
[Unit]
Description=Nexus telemetry agent
After=docker.service
Requires=docker.service

[Service]
Environment=NEXUS_URL=https://nexus.example.com
Environment=NEXUS_AGENT_TOKEN=paste-your-token
Environment=NEXUS_AGENT_INTERVAL_SECONDS=30
ExecStart=/usr/bin/node /opt/nexus-agent/reference-agent.mjs
Restart=on-failure
RestartSec=15s

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nexus-agent
journalctl -u nexus-agent -f
```

## Payload contract

The agent POSTs JSON to `${NEXUS_URL}/agent/telemetry`:

```jsonc
{
    "recorded_at": "2026-05-01T19:55:30.123Z",
    "host": {
        "facts": {
            "cpu_count": 4,
            "memory_total_mb": 8192,
            "os": "Ubuntu 24.04",
            "docker_version": "26.1.0"
        },
        "metrics": {
            "cpu_percent": null,
            "memory_used_mb": null,
            "load_average": null,
            "network_rx_bytes": null,
            "network_tx_bytes": null
        }
    },
    "containers": [
        {
            "container_id": "abc123…",
            "name": "web-1",
            "image": "ghcr.io/acme/web",
            "image_tag": "v1.2.3",
            "status": "running",
            "state": "running",
            "health_status": "healthy",
            "ports": ["0.0.0.0:8080->80/tcp"],
            "labels": { "com.docker.compose.service": "web" },
            "metrics": {
                "cpu_percent": 4.21,
                "memory_usage_mb": 128,
                "memory_limit_mb": 512,
                "network_rx_bytes": 1024000,
                "network_tx_bytes": 512000,
                "block_read_bytes": 0,
                "block_write_bytes": 0
            }
        }
    ]
}
```

`recorded_at` must be within ±1h past / +5min future of the server's clock; outside that window the request is rejected with 422. Set up NTP on the host.

## Limits

- This reference agent leaves host-level CPU/memory/load null. The container-level metrics are populated from `docker stats --no-stream`. A production Linux agent would read `/proc/loadavg`, `/proc/meminfo`, etc.
- It does not retry per-tick failures — relies on the supervisor to restart on hard failures and the next interval to recover from soft ones.
- Each call shells out to three `docker` invocations. On a host with thousands of containers this can take a few seconds; tune `NEXUS_AGENT_INTERVAL_SECONDS` accordingly.
