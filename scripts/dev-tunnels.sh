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
