# Dev Tunnels Script — Design

**Date:** 2026-05-20
**Owner:** Yoany Vaillant
**Status:** Design approved, ready for implementation plan

## Goal

Replace the manual 3-terminal-plus-edit-`.env` ritual described in
[`README.md`](../../../README.md) (the "Browsing the dev UI through a Cloudflare
tunnel" section) with a single command that brings up the full tunneled dev
stack from scratch.

One command, no copy-paste of trycloudflare URLs into `.env`.

## Non-goals

- **Named tunnels.** Only quick (`cloudflared tunnel --url ...`) tunnels. Named
  tunnels need account/zone config that lives outside the repo.
- **`.env` rollback.** When the script exits, `.env` keeps the tunnel URLs.
  Restoring localhost defaults is manual (re-run with localhost or git-checkout
  the file).
- **GitHub App callback automation.** The script will *remind* the user to
  update the OAuth callback URL after the Laravel tunnel URL changes; it does
  not call the GitHub API to update it.
- **Production / staging.** Dev-only utility. Never run against prod.

## Inputs (decisions already locked in)

| Question | Decision |
|---|---|
| On exit (Ctrl+C / crash) | Kill the 3 cloudflared PIDs. Leave `.env` tunneled. |
| Artisan command before `composer dev` | `php artisan optimize` (user's call, against the recommendation to use `optimize:clear`; see "Known footgun" below) |
| Script location + form | `scripts/dev-tunnels.sh`, bash, invoked as `./scripts/dev-tunnels.sh` |
| Session / Sanctum handling | Auto-blank `SESSION_DOMAIN`, auto-write `SANCTUM_STATEFUL_DOMAINS=<laravel-host>,localhost,127.0.0.1` |
| OAuth callback drift | Print a yellow reminder at the end pointing at the GitHub App settings URL |

## Architecture

Single bash script with `set -euo pipefail`. Execution order:

```
preflight  →  boot 3 tunnels in parallel  →  capture URLs (30s timeout each)  →
rewrite .env (7 keys)  →  clear + php artisan optimize  →
print OAuth callback reminder  →  run composer run dev (foreground, no exec)
```

Trap on `INT TERM EXIT` kills the 3 backgrounded cloudflared PIDs. **Do not
`exec` into composer** — `exec` replaces the bash process, which discards the
trap, leaving the tunnels orphaned on Ctrl+C. Run composer in the foreground
under bash so the trap fires when composer exits or the user Ctrl+Cs.

### Preflight

Fail fast with a single-line remedy:

- `cloudflared` is on `PATH` (else: install hint — `brew install cloudflared`).
- `.env` exists in the working directory (else: `cp .env.example .env`).
- Ports 8000 / 5173 / 8080 are free (else: `lsof -i :PORT` to find the culprit).

### Tunnel boot + URL capture

Boot the 3 quick tunnels in parallel — they have no dependency on each other,
so sequential boot would cost ~20–30s for nothing.

```bash
cloudflared tunnel --url http://localhost:8000 >/tmp/nexus-tunnel-8000.log 2>&1 &
LARAVEL_PID=$!
cloudflared tunnel --url http://localhost:5173 >/tmp/nexus-tunnel-5173.log 2>&1 &
VITE_PID=$!
cloudflared tunnel --url http://localhost:8080 >/tmp/nexus-tunnel-8080.log 2>&1 &
REVERB_PID=$!
TUNNEL_PIDS=("$LARAVEL_PID" "$VITE_PID" "$REVERB_PID")
```

Per-tunnel URL capture: 30s bounded poll on the log file for the
`https://*.trycloudflare.com` line cloudflared emits once the tunnel is
established.

```bash
wait_for_url() {
  local log=$1 url=""
  for _ in {1..60}; do
    url=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" | head -1)
    [[ -n "$url" ]] && { printf '%s' "$url"; return 0; }
    sleep 0.5
  done
  return 1
}
```

If any of the 3 captures times out: kill the other two tunnels, print the
failing log path, exit 1.

### `.env` rewrite (idempotent, per-key)

BSD sed (macOS) in-place edit. 6 of the 7 target keys already exist in the
current `.env`; `SANCTUM_STATEFUL_DOMAINS` does not. So: replace-if-exists,
else append.

```bash
write_env() {
  local key=$1 val=$2
  if grep -qE "^${key}=" .env; then
    sed -i '' "s|^${key}=.*|${key}=${val}|" .env
  else
    printf '%s=%s\n' "$key" "$val" >> .env
  fi
}

LARAVEL_HOST="${LARAVEL_URL#https://}"

write_env APP_URL                  "$LARAVEL_URL"
write_env VITE_DEV_SERVER_URL      "$VITE_URL"
write_env VITE_REVERB_HOST         "${REVERB_URL#https://}"   # bare host
write_env VITE_REVERB_SCHEME       "https"
write_env VITE_REVERB_PORT         "443"
write_env SESSION_DOMAIN           ""                          # blank
write_env SANCTUM_STATEFUL_DOMAINS "${LARAVEL_HOST},localhost,127.0.0.1"
```

Re-running the script just overwrites — no backup file, no diff, idempotent.

### Cleanup + final exec

```bash
cleanup() {
  kill "${TUNNEL_PIDS[@]}" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

clear
php artisan optimize

cat <<EOF
⚠ Reminder: update your GitHub App's OAuth callback URL to:
    ${LARAVEL_URL}/integrations/github/callback
    (https://github.com/settings/developers — or your GitHub App settings page)
EOF

composer run dev   # foreground; trap fires on its exit / Ctrl+C
```

## Known footgun (documented, not solved)

The user explicitly chose `php artisan optimize` over `optimize:clear`.
Implication: after this script runs, `bootstrap/cache/config.php` caches the
new tunneled `.env`. **Any further `.env` edits during the dev session are
silently ignored** until `php artisan optimize:clear` (or another full
restart).

In practice this is fine for the intended workflow — boot tunneled, work,
Ctrl+C, done — but worth flagging if the script ever shows up in onboarding
docs. If this turns into a problem in the future, the one-line fix is to swap
`optimize` for `optimize:clear` in the script.

## Error handling

| Failure | Behavior |
|---|---|
| `cloudflared` not installed | Print install hint, exit 1, no tunnels started. |
| `.env` missing | Print `cp .env.example .env` hint, exit 1. |
| Port already in use | Print `lsof -i :PORT` hint, exit 1, no tunnels started. |
| Tunnel times out (no URL in 30s) | Kill the other tunnels, print failing log path, exit 1. |
| `php artisan optimize` fails | Tunnels die via trap, `.env` keeps the tunnel URLs (idempotent — re-run). |
| `composer run dev` fails / Ctrl+C | Tunnels die via trap. `.env` unchanged. |

## File layout

```
scripts/
    dev-tunnels.sh        — new file, executable (chmod +x)
```

Nothing else in the repo changes. No new composer scripts, no new env keys,
no edits to existing dotfiles.

## Verification (manual, for after implementation)

1. With nothing running: `./scripts/dev-tunnels.sh` — observe 3 tunnels boot,
   `.env` get rewritten, composer dev start.
2. Open the printed `APP_URL` in a browser — page loads, no CORS / 403
   errors from Vite or Reverb. The Vite tunnel-mode banner appears in
   composer dev output.
3. Ctrl+C — tunnels die (`pgrep cloudflared` returns nothing), `.env` still
   has the tunnel URLs.
4. Re-run with a port already taken (e.g. `php artisan serve` already on 8000)
   — script exits before booting any tunnel with a clear remedy.
5. Simulate a tunnel timeout by killing one cloudflared mid-boot — other two
   die, log path is printed.

## Out of scope for this spec (future ideas)

- A `--no-reverb` flag that boots only 2 tunnels for users who don't need
  real-time features.
- A `--restore` flag to revert `.env` to localhost defaults from
  `.env.example` without re-running the full boot.
- Wiring into `composer dev:tunneled` if the script becomes a daily-driver
  workflow.
