# `scripts/dev-tunnels.sh` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a single bash script that spawns 3 cloudflared quick tunnels, rewrites the matching `.env` keys, runs `php artisan optimize`, and execs `composer run dev` — replacing the documented 3-terminal-plus-manual-`.env`-edit ritual.

**Architecture:** One bash script under `scripts/`, ~100 lines. Strict mode (`set -euo pipefail`). Preflight → parallel tunnel boot → log polling for URL capture → idempotent per-key sed rewrite of `.env` → cleanup trap on `INT TERM EXIT` → `composer run dev` in the foreground so the trap fires on Ctrl+C.

**Tech Stack:** Bash 3.2+ (macOS default), BSD sed, `cloudflared`, the existing Laravel + composer tooling.

**Reference spec:** [`docs/superpowers/specs/2026-05-20-dev-tunnels-script-design.md`](../specs/2026-05-20-dev-tunnels-script-design.md).

**Verification strategy:** This codebase has no bash test framework (it's a Laravel + Vue app — PHPUnit and vue-tsc). Verification is per-task observed behavior, plus a final end-to-end smoke test. `shellcheck` is optional but recommended (`brew install shellcheck`) — if available, run it as a static check.

---

## File Structure

| Path | Status | Responsibility |
|---|---|---|
| `scripts/dev-tunnels.sh` | new, executable | The entire script. Single file, top-to-bottom procedural. |

Nothing else changes. No new composer scripts, no new env keys, no edits to existing dotfiles.

---

## Task 1: Scaffolding + preflight checks

**Files:**
- Create: `scripts/dev-tunnels.sh` (chmod +x)

- [ ] **Step 1: Create the script with shebang, strict mode, and preflight**

```bash
mkdir -p scripts
cat > scripts/dev-tunnels.sh <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."   # always run from repo root

die() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
info() { printf '\033[36m▸ %s\033[0m\n' "$*"; }
ok() { printf '\033[32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m⚠ %s\033[0m\n' "$*"; }

preflight() {
  command -v cloudflared >/dev/null \
    || die "cloudflared not on PATH — install with: brew install cloudflared"
  [[ -f .env ]] \
    || die ".env not found — run: cp .env.example .env && php artisan key:generate"
  for port in 8000 5173 8080; do
    if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
      die "port $port already in use — find it with: lsof -nP -iTCP:$port -sTCP:LISTEN"
    fi
  done
  ok "preflight passed"
}

preflight
SCRIPT
chmod +x scripts/dev-tunnels.sh
```

- [ ] **Step 2: Verify preflight runs cleanly (all 3 ports free, .env exists)**

Run: `./scripts/dev-tunnels.sh`
Expected: `▸ ... ✓ preflight passed` and exit 0. **Nothing else** — no tunnels yet.

- [ ] **Step 3: Verify preflight fails clearly when port 8000 is taken**

Run in two terminals:
```bash
# Terminal A — occupy 8000
python3 -m http.server 8000

# Terminal B
./scripts/dev-tunnels.sh
```
Expected (Terminal B): `✗ port 8000 already in use — find it with: lsof -nP -iTCP:8000 -sTCP:LISTEN`, exit 1.
Cleanup: Ctrl+C in Terminal A.

- [ ] **Step 4: Commit**

```bash
git add scripts/dev-tunnels.sh
git commit -m "feat(scripts): scaffold dev-tunnels.sh with preflight checks"
```

---

## Task 2: Boot 3 tunnels in parallel + capture URLs

**Files:**
- Modify: `scripts/dev-tunnels.sh` (append after `preflight` call)

- [ ] **Step 1: Add tunnel boot + URL capture + cleanup trap**

Append to `scripts/dev-tunnels.sh` (before the final newline if any). The new code goes after the `preflight` call from Task 1:

```bash
TUNNEL_PIDS=()
LOG_DIR=$(mktemp -d -t nexus-tunnels.XXXXXX)
info "tunnel logs: $LOG_DIR"

cleanup() {
  if ((${#TUNNEL_PIDS[@]})); then
    kill "${TUNNEL_PIDS[@]}" 2>/dev/null || true
    wait 2>/dev/null || true
  fi
}
trap cleanup INT TERM EXIT

boot_tunnel() {
  local port=$1 log="$LOG_DIR/tunnel-$1.log"
  cloudflared tunnel --url "http://localhost:$port" >"$log" 2>&1 &
  TUNNEL_PIDS+=("$!")
  printf '%s' "$log"
}

wait_for_url() {
  local log=$1 url=""
  for _ in $(seq 1 60); do
    url=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" 2>/dev/null | head -1 || true)
    if [[ -n "$url" ]]; then
      printf '%s' "$url"
      return 0
    fi
    sleep 0.5
  done
  return 1
}

info "booting 3 cloudflared tunnels in parallel…"
LARAVEL_LOG=$(boot_tunnel 8000)
VITE_LOG=$(boot_tunnel 5173)
REVERB_LOG=$(boot_tunnel 8080)

LARAVEL_URL=$(wait_for_url "$LARAVEL_LOG") || die "Laravel tunnel timed out — see $LARAVEL_LOG"
ok "Laravel  → $LARAVEL_URL"
VITE_URL=$(wait_for_url "$VITE_LOG") || die "Vite tunnel timed out — see $VITE_LOG"
ok "Vite     → $VITE_URL"
REVERB_URL=$(wait_for_url "$REVERB_LOG") || die "Reverb tunnel timed out — see $REVERB_LOG"
ok "Reverb   → $REVERB_URL"
```

- [ ] **Step 2: Run the script and confirm all 3 URLs are captured**

Run: `./scripts/dev-tunnels.sh`
Expected output (URLs will vary):
```
▸ tunnel logs: /tmp/nexus-tunnels.XXXXXX
▸ booting 3 cloudflared tunnels in parallel…
✓ Laravel  → https://<random>.trycloudflare.com
✓ Vite     → https://<random>.trycloudflare.com
✓ Reverb   → https://<random>.trycloudflare.com
```
The script will then exit (we haven't added the `.env` rewrite yet), and the `EXIT` trap will kill the 3 cloudflared processes.

- [ ] **Step 3: Verify tunnels were cleaned up on exit**

Run: `pgrep -lf cloudflared || echo "no cloudflared processes"`
Expected: `no cloudflared processes`

- [ ] **Step 4: Verify timeout behavior by pointing one tunnel at a non-existent port**

Edit `scripts/dev-tunnels.sh` temporarily:
```bash
# Change the line:
LARAVEL_LOG=$(boot_tunnel 8000)
# To:
LARAVEL_LOG=$(boot_tunnel 9999)   # nothing listens here
```
Run: `./scripts/dev-tunnels.sh`
Expected: still captures a tunnel URL (cloudflared assigns a URL even when nothing's behind it). This is fine — the timeout safety net is for cases where cloudflared itself fails (no network, auth issue, etc). Revert the edit before continuing.

- [ ] **Step 5: Commit**

```bash
git add scripts/dev-tunnels.sh
git commit -m "feat(scripts): boot 3 cloudflared tunnels and capture URLs"
```

---

## Task 3: Rewrite `.env` with the 7 keys

**Files:**
- Modify: `scripts/dev-tunnels.sh` (append after the URL captures)

- [ ] **Step 1: Add `write_env` and apply to the 7 keys**

Append to `scripts/dev-tunnels.sh`:

```bash
write_env() {
  local key=$1 val=$2
  if grep -qE "^${key}=" .env; then
    # Use | as sed delimiter since URLs contain /
    sed -i '' "s|^${key}=.*|${key}=${val}|" .env
  else
    printf '%s=%s\n' "$key" "$val" >> .env
  fi
}

LARAVEL_HOST="${LARAVEL_URL#https://}"
REVERB_HOST="${REVERB_URL#https://}"

info "rewriting .env…"
write_env APP_URL                  "$LARAVEL_URL"
write_env VITE_DEV_SERVER_URL      "$VITE_URL"
write_env VITE_REVERB_HOST         "$REVERB_HOST"
write_env VITE_REVERB_SCHEME       "https"
write_env VITE_REVERB_PORT         "443"
write_env SESSION_DOMAIN           ""
write_env SANCTUM_STATEFUL_DOMAINS "${LARAVEL_HOST},localhost,127.0.0.1"
ok ".env updated"
```

- [ ] **Step 2: Run the script and inspect `.env` to confirm rewrite**

Run: `./scripts/dev-tunnels.sh`
Then in another terminal (or after the script exits):
```bash
grep -E '^(APP_URL|VITE_DEV_SERVER_URL|VITE_REVERB_HOST|VITE_REVERB_SCHEME|VITE_REVERB_PORT|SESSION_DOMAIN|SANCTUM_STATEFUL_DOMAINS)=' .env
```
Expected: 7 lines, all reflecting the freshly-captured tunnel URLs. `APP_URL` and `VITE_DEV_SERVER_URL` are full `https://…trycloudflare.com` URLs; `VITE_REVERB_HOST` is just the bare hostname; `SESSION_DOMAIN=` is blank.

- [ ] **Step 3: Re-run to confirm idempotency (no duplicate keys, just overwrites)**

Run: `./scripts/dev-tunnels.sh`
Then check there are no duplicate lines:
```bash
grep -cE '^APP_URL=' .env                  # should be 1
grep -cE '^SANCTUM_STATEFUL_DOMAINS=' .env # should be 1
```
Expected: both print `1`.

- [ ] **Step 4: Commit**

```bash
git add scripts/dev-tunnels.sh
git commit -m "feat(scripts): rewrite .env with tunnel URLs + Sanctum/session config"
```

---

## Task 4: Optimize, OAuth reminder, hand off to `composer run dev`

**Files:**
- Modify: `scripts/dev-tunnels.sh` (append the final section)

- [ ] **Step 1: Add the optimize call, OAuth reminder, and composer dev**

Append to `scripts/dev-tunnels.sh`:

```bash
clear
info "running php artisan optimize…"
php artisan optimize

cat <<EOF

$(printf '\033[33m⚠ Reminder:\033[0m update your GitHub App OAuth callback URL to:')
    ${LARAVEL_URL}/integrations/github/callback

GitHub App settings: https://github.com/settings/developers (or your GitHub App
settings page if you registered as a GitHub App rather than an OAuth App).

EOF

info "handing off to composer run dev — Ctrl+C to stop everything"
composer run dev
```

Note: **no `exec`**. We want the bash process to stay alive so the cleanup trap fires when composer exits or the user Ctrl+Cs.

- [ ] **Step 2: End-to-end smoke run**

Run: `./scripts/dev-tunnels.sh`
Expected:
1. Preflight passes.
2. 3 tunnel URLs print.
3. `.env` rewritten (no duplicates).
4. `clear` wipes the terminal.
5. `php artisan optimize` runs (you'll see config/route/event/view caching lines).
6. The OAuth reminder prints with the new `APP_URL`.
7. `composer run dev` boots — you'll see colored `server`, `queue`, `reverb`, `scheduler`, `logs`, `vite` rows from `concurrently`.
8. Look for the `[vite] tunnel mode active — origin=…` line in the `vite:` rows. If it's missing, the script didn't successfully wire `VITE_DEV_SERVER_URL` — debug `.env`.
9. Open the printed `APP_URL` in a browser. Sign-in/Overview should load with no CORS / 419 / Mixed Content errors.

- [ ] **Step 3: Verify Ctrl+C cleanup**

In the running script: press Ctrl+C.
Expected:
1. `concurrently` tears down server / queue / reverb / scheduler / logs / vite.
2. The script exits (the `EXIT` trap fires after composer exits).
3. Run `pgrep -lf cloudflared || echo "all clean"` — expected `all clean`.
4. `.env` still has the tunnel URLs (per the design decision: leave `.env` tunneled).

- [ ] **Step 4: Commit**

```bash
git add scripts/dev-tunnels.sh
git commit -m "feat(scripts): optimize, OAuth reminder, hand off to composer dev"
```

---

## Task 5: Optional static analysis + final polish

- [ ] **Step 1: Run shellcheck if available**

```bash
command -v shellcheck && shellcheck scripts/dev-tunnels.sh || echo "shellcheck not installed — skipping (brew install shellcheck)"
```
Expected: either no output (clean) or a list of warnings to address. Fix any genuine issues; suppressions are okay if they're false positives (e.g. `# shellcheck disable=SC2155` is common for `local x=$(…)`).

- [ ] **Step 2: README touch-up (optional but recommended)**

The README's "Browsing the dev UI through a Cloudflare tunnel" section walks through the manual 3-tunnel ritual. Add a one-paragraph "Shortcut" callout pointing at the new script.

Modify the section's intro paragraph in `README.md` near line 130:

```markdown
> **Shortcut:** `./scripts/dev-tunnels.sh` does all of the steps below in one
> command — boots 3 cloudflared tunnels, rewrites `.env`, runs
> `php artisan optimize`, and hands off to `composer run dev`. Read on if you
> want to understand what it's doing or you need named tunnels instead.
```

- [ ] **Step 3: Commit (one commit for both, if you did step 2)**

```bash
git add scripts/dev-tunnels.sh README.md
git commit -m "chore(scripts): shellcheck pass + README shortcut callout"
```

---

## End-to-end acceptance criteria

Before declaring done, all of these should be true on a fresh terminal session:

- [ ] `./scripts/dev-tunnels.sh` boots from a clean repo state and ends with a working `composer run dev` running through 3 tunnels.
- [ ] Browser can load the Overview page at the printed `APP_URL` with no console errors (no CORS from Vite, no 419 from sessions, no Mixed Content from forced HTTPS).
- [ ] Ctrl+C in the running script kills all 3 cloudflared processes (`pgrep -lf cloudflared` returns nothing).
- [ ] Running the script twice in a row does not duplicate any `.env` key (`grep -cE '^APP_URL=' .env` returns `1`).
- [ ] If any port (8000 / 5173 / 8080) is already taken, the script exits with a clear remedy *before* booting any tunnel.
- [ ] If `cloudflared` is missing or `.env` is missing, the script exits with a clear install/setup remedy.

If any of the above fails, fix the script — don't paper over with documentation.
