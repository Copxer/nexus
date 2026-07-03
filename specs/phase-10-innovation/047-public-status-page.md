---
spec: public-status-page
phase: 10
status: done   # not-started | in-progress | blocked | done
owner: Yoany
created: 2026-07-02
updated: 2026-07-02
---

# 047 — Public status page generator + subscribers

## Goal

Every SaaS ships one: a public `/status/{slug}` page an operator can
link from their landing page so customers know at a glance whether
things are up. Phase 10 wraps up its innovation slice with this —
Nexus already has the raw data (spec 025's uptime windows, spec 030's
alerts, spec 038's system health) and needs one thin public surface
+ a subscriber list + a delivery loop.

Roadmap refs: §Phase 10 Future Features ("Status page generator"),
§14 Analytics (uptime windows already computed),
§Phase 7 alerts (feeds the incidents strip), spec 042 delivery layer
(reused for subscriber notifications).

## Scope

**In scope:**

- **Per-project opt-in.** Add `public_status_enabled` (boolean,
  default false) + `public_status_headline` (nullable string,
  operator-provided banner text) to the `projects` table. The
  existing `slug` column becomes the public URL segment.

- **Public route `GET /status/{project:slug}`.** Unauthenticated,
  guest-safe, no CSRF cookie needed. Renders a lightweight Inertia
  page (`Pages/Status/Show.vue`) with:
  - **Overall status band** — `Operational` / `Degraded` /
    `Partial outage` / `Major outage`. Computed from open-alert
    count + severity + monitor status.
  - **Monitor strip** — one row per website belonging to the
    project, showing 24h + 7d + 30d uptime %.
  - **Recent incidents** — the last 10 resolved alerts (title,
    severity, opened / resolved timestamps).
  - **Active incidents** — open + acknowledged alerts (title,
    severity, opened at).
  - **Subscribe form** — email input + submit. Client-side + server-
    side email validation, honeypot field for bots.
  - **Powered-by-Nexus** footer link.
  - Rate limit: `throttle:120,1` on the view (public traffic).

- **Cached status snapshot.** `GetPublicStatusPageQuery::execute($project)`
  returns the assembled DTO. Wrapped in `Cache::remember("public-status:{$project->id}", 60, ...)`
  so a viral share doesn't hammer the DB. Cache invalidated on
  every relevant transition — alert trigger/resolve on the project
  fires a `Cache::forget("public-status:{$project->id}")` via a
  new `InvalidatePublicStatusCacheListener`.

- **Subscribers table.**
  ```
  public_status_subscribers
    id
    project_id (FK → projects, cascade delete)
    email      (string 190, indexed)
    confirmation_token   (string 64, unique)
    unsubscribe_token    (string 64, unique)
    confirmed_at (nullable timestamp)
    timestamps
  ```
  Composite unique on `(project_id, email)` — one subscription per
  email per project. Deleting a project cascades to subscribers.

- **Double opt-in flow.**
  - `POST /status/{project:slug}/subscribe` — validates email, honey-
    pot, throttle `20,1`. Creates a `public_status_subscribers` row
    with a fresh `confirmation_token` + `unsubscribe_token`, sends a
    confirmation email (via Laravel `Mail::to`). Never confirms
    directly — every subscription requires the round trip so a
    stranger can't sign up your inbox for someone else's status.
  - `GET /status/{project:slug}/confirm/{token}` — flips
    `confirmed_at`, renders a "You're subscribed" page. Unknown
    token → 404.
  - `GET /status/subscribers/unsubscribe/{token}` — one-click
    unsubscribe (RFC 8058 style, one-URL). Deletes the row. Unknown
    token → 404. Rate-limited `throttle:60,1`.

- **Notification loop.**
  - `NotifyStatusSubscribersJob(alertId)` — takes a triggered
    `Alert` row, resolves the alert's project, iterates confirmed
    subscribers, mails each with an incident summary.
  - Dispatched from a new `NotifyPublicSubscribersOnAlertListener`
    that listens for `AlertTriggered` + `AlertResolved` (spec 032
    events). Fire-and-forget from the listener — an unreachable
    SMTP shouldn't taint the alert lifecycle.
  - Deduped: same `(project_id, alert_id)` won't fire more than
    once per event (`ShouldBeUnique` keyed on both).
  - Skipped when `project.public_status_enabled` is false or when
    no confirmed subscribers exist.
  - **Not** riding on spec 042's `AlertNotificationService`. That
    service scopes preferences to authenticated users; public
    subscribers are anonymous and use a separate delivery path.

- **Emails.**
  - `PublicStatusSubscribeConfirmationMail` — bare confirm link.
  - `PublicStatusIncidentMail` — incident title + severity + open /
    resolve timestamp + link back to `/status/{slug}` + one-click
    unsubscribe.
  - Both templates minimal; the header + body match spec 042's
    email visual language (dark card, severity color, footer
    disclaimer).

- **Settings surface.** Extend the project edit page (or add a
  small `Settings/Projects/PublicStatus.vue` panel) with:
  - Toggle for `public_status_enabled`.
  - Text input for `public_status_headline`.
  - "Public URL" preview + copy button (once enabled).
  - Subscriber count read-only.
  - Rate limit: form endpoint `throttle:20,1`.

- **Palette command.** New "Copy public status URL for {project}"
  action (spec 043 palette) surfaced when the palette query matches
  the project name and public status is enabled. Deferred if it
  requires per-entity dynamic commands the palette scaffold doesn't
  support today — ship as a static "Open public status" nav if the
  dynamic-command dependency is too big.

- **Tests.**
  - `PublicStatusPageControllerTest` — happy path renders for an
    enabled project; disabled project 404s; unknown slug 404s;
    throttle engaged after 120/min.
  - `PublicStatusSubscribeControllerTest` — happy path creates a
    row + sends a confirmation email (`Mail::fake()`); duplicate
    email is idempotent (updates token, doesn't create second
    row); honeypot filled 422; throttle at 20/min; invalid email
    422; disabled project 404.
  - `PublicStatusSubscribeConfirmationTest` — confirm flips
    `confirmed_at`; unknown token 404; already-confirmed second
    hit stays idempotent.
  - `PublicStatusUnsubscribeControllerTest` — happy path deletes
    the row; unknown token 404.
  - `NotifyStatusSubscribersJobTest` — fires only to confirmed
    subscribers; skips when project has `public_status_enabled=false`;
    skips when zero confirmed rows; `Mail::fake()` proves the
    per-subscriber send.
  - `NotifyPublicSubscribersOnAlertListenerTest` — `AlertTriggered`
    on an enabled project enqueues the job; `AlertTriggered` on a
    disabled project doesn't; `AlertResolved` triggers the same
    path with an appropriate subject.
  - `GetPublicStatusPageQueryTest` — assembled DTO shape matches
    spec (overall band + monitors + active + recent); cache key /
    TTL respected; forget-on-transition proven via
    `Cache::spy()`.

**Out of scope:**

- **Historical uptime graph beyond 30d.** The page shows 24h + 7d +
  30d; older windows land in a follow-up (needs a rollup table
  because scanning `website_checks` for 90+ days is expensive).
- **Component groups.** Statuspage.io-style "Storefront →
  {Frontend, API, CDN}" nested components. Nexus's data model is
  flat (projects → websites); nested grouping is a Phase 11 UX
  redesign, not a Phase 10 deliverable.
- **Custom domain per status page.** `status.customer.com` requires
  wildcard TLS + a DNS-verification loop. Ship `/status/{slug}`
  first; custom domains are a follow-up.
- **Custom theming per project.** Body colors match Nexus's dark
  theme. Operators asking for their brand colors can wait for a
  Phase 11 theming spec.
- **Incident postmortem drafting.** A separate long-form incident
  writeup UI is a real feature but overlaps with the deferred
  "Incident management" (Phase 11 wishlist).
- **Slack / webhook subscriber channels.** Only email in v1.
  Extending the subscribers table to carry a `channel_kind` +
  `config` opens the door to Slack/webhook parity later.
- **Anti-spam captcha.** Honeypot + `throttle:20,1` is enough for
  v1. Real captcha (hCaptcha / Turnstile) is a follow-up if
  operators hit spam.
- **RSS / iCalendar feed for incidents.** Nice for downstream
  monitoring; defer.

## Plan

1. **Migration + model tweak.** Add `public_status_enabled` +
   `public_status_headline` to `projects`. New
   `public_status_subscribers` table + model + factory.
2. **Query class.** `GetPublicStatusPageQuery::execute($project)` —
   reads uptime windows, alerts, computes overall band. Cache-
   remember wrapper.
3. **Public controllers.**
   `App\Http\Controllers\PublicStatus\ShowController` (index),
   `SubscribeController` (POST subscribe + GET confirm),
   `UnsubscribeController` (GET unsubscribe). All under a shared
   `throttle` middleware group.
4. **Route registration.** Guest-safe `/status/*` group at the
   bottom of `routes/web.php`, no auth middleware.
5. **Notification pieces.** Two `Mailable` classes + Blade
   templates. `NotifyStatusSubscribersJob` +
   `NotifyPublicSubscribersOnAlertListener` (wired in
   `EventServiceProvider`).
6. **Cache invalidation listener.**
   `InvalidatePublicStatusCacheListener` on `AlertTriggered` +
   `AlertResolved` + potentially `WebsiteCheckRecorded` (throttled
   so a chatty monitor doesn't flush the cache every 60s).
7. **Settings UI.** Extend the project edit form or ship a small
   dedicated panel — pick whichever integrates cleanest with the
   Phase 1 project CRUD.
8. **Public status page Vue component.** Simple static-feeling
   render; no realtime layer (operators viewing don't need
   sub-minute updates on a public page).
9. **Palette command.** Best-effort — ship if the current palette
   scaffold supports it without new plumbing.
10. **Docs.** Extend `docs/security/operator-checklist.md` §5 with
    the new endpoints; add a note about the double opt-in +
    unsubscribe token contract.
11. **Pint + suite + build + self-review + PR.**

## Acceptance criteria

- [ ] `/status/{project.slug}` renders unauthenticated for an
      opted-in project; 404s otherwise.
- [ ] Page shows overall status band, per-monitor uptime (24h/7d/30d),
      active incidents, recent incidents.
- [ ] Subscribe form creates a `public_status_subscribers` row,
      sends a confirmation email, respects honeypot + throttle.
- [ ] `GET /status/{slug}/confirm/{token}` flips `confirmed_at`;
      unknown token 404.
- [ ] `GET /status/subscribers/unsubscribe/{token}` deletes the row.
- [ ] `AlertTriggered` + `AlertResolved` on an opted-in project
      enqueue `NotifyStatusSubscribersJob`; disabled projects skip.
- [ ] `NotifyStatusSubscribersJob` mails only confirmed subscribers.
- [ ] Status snapshot is cached 60s; invalidated on alert
      trigger/resolve transitions.
- [ ] Every new endpoint throttled per §5 of the operator
      checklist.
- [ ] Every test in §Tests block green.
- [ ] Pint clean, `php artisan test` green, `npm run build`
      clean.

## Files touched

- `database/migrations/2026_07_02_*_add_public_status_fields_to_projects_table.php` — created
- `database/migrations/2026_07_02_*_create_public_status_subscribers_table.php` — created
- `app/Models/Project.php` — new fillables + casts for the two new columns
- `app/Models/PublicStatusSubscriber.php` — created
- `database/factories/PublicStatusSubscriberFactory.php` — created
- `app/Domain/PublicStatus/DataTransferObjects/PublicStatusSnapshot.php` — created
- `app/Domain/PublicStatus/Queries/GetPublicStatusPageQuery.php` — created (cache-remember wrapper)
- `app/Domain/PublicStatus/Jobs/NotifyStatusSubscribersJob.php` — created
- `app/Domain/PublicStatus/Listeners/InvalidatePublicStatusCacheListener.php` — created
- `app/Domain/PublicStatus/Listeners/NotifyPublicSubscribersOnAlertListener.php` — created
- `app/Http/Controllers/PublicStatus/ShowController.php` — created
- `app/Http/Controllers/PublicStatus/SubscribeController.php` — created
- `app/Http/Controllers/PublicStatus/UnsubscribeController.php` — created
- `app/Http/Controllers/PublicStatus/ConfirmSubscriptionController.php` — created
- `app/Mail/PublicStatusSubscribeConfirmationMail.php` — created
- `app/Mail/PublicStatusIncidentMail.php` — created
- `resources/views/emails/public-status/subscribe-confirmation.blade.php` — created
- `resources/views/emails/public-status/incident.blade.php` — created
- `app/Providers/EventServiceProvider.php` — register the two new listeners
- `resources/js/Pages/Status/Show.vue` — created (public page shell)
- `resources/js/Pages/Status/Confirmed.vue` — created (confirmation success page)
- `resources/js/Pages/Status/Unsubscribed.vue` — created (unsubscribe success page)
- `resources/js/Pages/Projects/*` — expose the public-status toggle + headline (extend Edit.vue or add a panel)
- `routes/web.php` — guest-safe `/status/*` group at the bottom
- `docs/security/operator-checklist.md` — §5 extended
- `tests/Feature/PublicStatus/PublicStatusPageControllerTest.php` — created
- `tests/Feature/PublicStatus/PublicStatusSubscribeControllerTest.php` — created
- `tests/Feature/PublicStatus/PublicStatusSubscribeConfirmationTest.php` — created
- `tests/Feature/PublicStatus/PublicStatusUnsubscribeControllerTest.php` — created
- `tests/Feature/PublicStatus/NotifyStatusSubscribersJobTest.php` — created
- `tests/Feature/PublicStatus/NotifyPublicSubscribersOnAlertListenerTest.php` — created
- `tests/Feature/PublicStatus/GetPublicStatusPageQueryTest.php` — created

## Work log

Dated notes as work progresses.

### 2026-07-02
- Drafted from `_template.md`. Phase 10 closer.
- **Not** riding on spec 042 — that service scopes to
  authenticated users; public subscribers are anonymous and
  use a distinct email-only delivery path. Sharing the code
  would force spec 042 to carry public-facing concerns
  (double opt-in, unsubscribe tokens, spam guards) it was
  designed to avoid.
- Kept `slug` as the URL segment (already unique + already the
  public-safe identifier). No random hash — operators want the
  URL to match their project's identity, and a project slug is
  fine to expose (it's already surfaced in every dashboard URL
  the operator uses).
- Cache TTL at 60s balances "fresh enough for a status page"
  vs. "not thrashing the DB under viral share." Invalidation on
  alert transitions gives the appearance of realtime without
  broadcasting to public viewers.
- Branch `spec/047-public-status-page` cut off main.
- Tracking issue #129.

## Open questions / blockers

- **Should the page auto-refresh?** Static render + no JS beyond
  what Inertia already ships. Operators viewing a status page
  don't need realtime; they refresh manually or wait 60s. The
  cache TTL enforces the freshness ceiling. Decision: no
  auto-refresh in v1.
- **Cache key salting on `public_status_headline`.** The headline
  is part of the page render, but changing it via Settings should
  reflect immediately. Bump the cache key on save
  (`Cache::forget` in the project update controller) rather than
  including the headline in the key itself.
- **Subscribers table growth.** No hard cap; one row per email
  per project, so a bad-actor SMTP won't grow the table beyond
  what their email list allows. Add a `public_status_subscribers`
  count in the settings panel so operators notice unusual
  growth. A hard cap is a follow-up.
- **Double-opt-in email as public spam vector?** A bot posts
  `victim@example.com` to a project's subscribe form → victim
  gets a confirmation email from Nexus. That's the standard
  double-opt-in tradeoff — the mail says "you're not subscribed
  yet; click here to confirm" so an unclicked confirmation
  costs the victim one email. `throttle:20,1` keeps the blast
  radius small. `MAIL_FROM_ADDRESS` should be the operator's
  own, not Nexus's, so replies land where they should — an
  optional per-project override on the mail from-address is a
  Phase 11 knob.
- **Public status URL exposure via robots.** No robots.txt
  intervention in v1. Operators who don't want crawler indexing
  can add their own `robots.txt` rules; adding `noindex` by
  default risks blocking operators who *want* their status page
  crawled (linked to marketing). Ship as-is; document.
- **Incident-notification cadence.** Every state change (trigger,
  resolve) sends an email. Spec 042's dedupe window (5 min) is
  the reference. Add a lightweight per-alert dedupe on the
  subscriber job so a flapping alert doesn't spam subscribers
  — check `alert.last_seen_at` moved less than 5 min since the
  last mail sent; skip if not. Simple heuristic; ship.
