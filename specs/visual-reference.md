---
spec: visual-reference
phase: cross-cutting
status: living-document
owner: yoany
created: 2026-04-27
updated: 2026-04-27
---

# Visual Reference — Overview Dashboard

The canonical visual target is [`../nexus-dashboard.png`](../nexus-dashboard.png). Every Overview-page task and every reusable dashboard component must match this look.

## What the screenshot shows

### Global chrome
- **Background:** deep navy/near-black with a faint diagonal gradient. No pure black; sits around `#020617 → #0B1220`.
- **Left sidebar (~240px):** NEXUS wordmark + small icon at top, vertical nav (Overview, Projects, Repositories, Issues & PRs, Pipelines, Hosts, Monitoring, Analytics, Settings), a user card near the bottom, and a small "System Status" indicator. Active item has a neon-cyan glow on the left edge and a subtle filled background.
- **Top bar:** page title ("Overview") on the left; on the right a search field, time-range selector, theme toggle, notifications bell, user avatar.

### KPI row (full width, 6 cards)
Projects · Deployments · Services · Hosts · Alerts · Uptime. Each card:
- Icon top-left with a soft accent-colored glow
- Big numeric value
- Tiny secondary label ("Successful", "Running", "Online", etc.)
- Trend chip (↑ 18%, ↑ 0.01, etc.) — green for positive, red for negative
- A faint mini sparkline behind/under the number on some cards
- Status dot or badge (green = healthy, amber = warn, red/pink = danger)

### Main grid widgets (clockwise from top-left)
1. **Issues & Pull Requests** — list with priority chips (`Critical`, `High`, `Medium`, `Low`) in red/amber/blue, repo and time metadata, count summary at top.
2. **Global Infrastructure (map)** — dark stylized world map, glowing node dots in cyan/magenta with thin neon arcs between regions, latency labels (e.g. `843 ms`, `42ms`).
3. **Website Performance** — large area/line chart in cyan→magenta gradient, headline numbers (uptime %, response ms), small status pills.
4. **Activity Feed (right rail)** — chronological list of events with colored icons, two-line entries (title + meta), timestamps right-aligned.
5. **Container Hosts** — compact list of hosts with mini CPU/memory bars and status dots.
6. **Service Health** — service rows with green/amber/red dots and a tiny inline sparkline.
7. **Resource Utilization** — multi-line chart (cyan + purple + magenta lines) on a dark grid.
8. **Top Repositories** — leaderboard list with small bars.
9. **Heatmap Activity** — 7×24-ish grid of rounded squares, intensity ramp from deep purple to bright magenta/pink.
10. **Deployment Timeline** — horizontal timeline at the bottom with success/fail markers.
11. **System Metrics** — row of ring/donut gauges (e.g. 45%, 68%, 55%) with neon strokes.

## Color tokens (locked from screenshot + §7.3)

```text
background.base       #020617
background.gradient   linear-gradient(135deg, #020617 0%, #0B1220 100%)
background.panel      rgba(15, 23, 42, 0.72)
background.panelHover rgba(30, 41, 59, 0.85)

border.subtle         rgba(148, 163, 184, 0.16)
border.active         rgba(56, 189, 248, 0.5)

text.primary          #F8FAFC
text.secondary        #CBD5E1
text.muted            #64748B

accent.blue           #38BDF8
accent.cyan           #22D3EE
accent.purple         #8B5CF6
accent.magenta        #D946EF

status.success        #22C55E
status.warning        #F59E0B
status.danger         #EF4444
status.info           #3B82F6
```

## Visual rules every component must follow

- **Card chrome:** `rounded-2xl`, `bg-slate-950/70`, `border border-slate-700/40`, `backdrop-blur-xl`, soft `shadow-2xl`. Hover lifts the border to `border-cyan-400/40`.
- **Glow:** reserved for active states — sidebar active item, online status dots, primary CTA, map nodes, critical alerts. Never glow neutral elements.
- **Typography:** Inter (or Geist) for UI; JetBrains Mono for numbers, hashes, host names, IDs, metric values.
- **Numbers:** large numerics use tabular-nums and a slight letter-spacing tighten. Trend chips are small and pill-shaped.
- **Charts:** dark grid lines `rgba(148,163,184,0.08)`, neon strokes (cyan/purple/magenta), area fills as gradients fading to transparent.
- **Spacing:** generous gutters (`gap-4` to `gap-6`), 12-column grid on desktop.
- **Motion:** smooth opacity/translate transitions only; no bouncy or distracting motion. Hover transitions ≤ 200ms.

## Anti-patterns (do not ship)
- Pure black `#000` backgrounds — always navy-tinted.
- Neon on every element — kills the signal.
- Drop shadows mimicking light mode.
- Stock chart libraries with default light styles.
- Tables with hairline 1px borders that disappear on dark.

## How to use this file
- Every Phase 0 component spec links here.
- When implementing a card or chart, open `nexus-dashboard.png` side-by-side and match it.
- If a design decision conflicts with this file, update this file first (with rationale in Work log) before changing the implementation.

## Work log

### 2026-04-27
- Captured visual reference from `nexus-dashboard.png` provided by the user.
- Locked color tokens against §7.3 of the roadmap (no conflicts).
