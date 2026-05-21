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

TUNNEL_PIDS=()
LOG_DIR=$(mktemp -d -t nexus-tunnels)
info "tunnel logs: $LOG_DIR"

cleanup() {
  # Tunnel logs in $LOG_DIR are intentionally left behind for postmortem.
  if ((${#TUNNEL_PIDS[@]})); then
    kill "${TUNNEL_PIDS[@]}" 2>/dev/null || true
    wait "${TUNNEL_PIDS[@]}" 2>/dev/null || true
  fi
}
trap cleanup INT TERM EXIT

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
LARAVEL_LOG="$LOG_DIR/tunnel-8000.log"
VITE_LOG="$LOG_DIR/tunnel-5173.log"
REVERB_LOG="$LOG_DIR/tunnel-8080.log"

cloudflared tunnel --url "http://localhost:8000" >"$LARAVEL_LOG" 2>&1 &
TUNNEL_PIDS+=("$!")
cloudflared tunnel --url "http://localhost:5173" >"$VITE_LOG" 2>&1 &
TUNNEL_PIDS+=("$!")
cloudflared tunnel --url "http://localhost:8080" >"$REVERB_LOG" 2>&1 &
TUNNEL_PIDS+=("$!")

LARAVEL_URL=$(wait_for_url "$LARAVEL_LOG") || die "Laravel tunnel timed out — see $LARAVEL_LOG"
ok "Laravel  → $LARAVEL_URL"
VITE_URL=$(wait_for_url "$VITE_LOG") || die "Vite tunnel timed out — see $VITE_LOG"
ok "Vite     → $VITE_URL"
REVERB_URL=$(wait_for_url "$REVERB_LOG") || die "Reverb tunnel timed out — see $REVERB_LOG"
ok "Reverb   → $REVERB_URL"

write_env() {
  local key=$1 val=$2
  if grep -qE "^${key}=" .env; then
    # Delimiter is | (URLs contain /). Safe from sed-injection because val is
    # regex-constrained by wait_for_url — no |, &, \ possible.
    sed -i '' "s|^${key}=.*|${key}=${val}|" .env
  else
    # Guarantee a trailing newline so the append can't merge onto the last line.
    [[ -s .env && -z $(tail -c1 .env) ]] || printf '\n' >> .env
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

clear || true
info "running php artisan optimize…"
php artisan optimize

warn "Reminder: update your GitHub App OAuth callback URL to:"
cat <<EOF
    ${LARAVEL_URL}/integrations/github/callback

GitHub App settings: https://github.com/settings/developers (or your GitHub App
settings page if you registered as a GitHub App rather than an OAuth App).

Tunnels (also written to .env):
    Laravel  ${LARAVEL_URL}
    Vite     ${VITE_URL}
    Reverb   ${REVERB_URL}

EOF

info "handing off to composer run dev — Ctrl+C to stop everything"
composer run dev
