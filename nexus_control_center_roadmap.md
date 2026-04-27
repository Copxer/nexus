# Nexus Control Center — Full Project Roadmap & AI Coding Agent Build Specification

> **Project concept:** A futuristic operations dashboard / engineering hub where you can monitor all your products, GitHub repositories, issues, pull requests, deployments, Docker hosts, uptime, website load speed, alerts, activity heatmaps, maps, and system health from one premium command center.

---

## 1. Product Vision

### 1.1 Product Name

Working name: **Nexus Control Center**

Alternative names:

- **OrbitOps**
- **CommandHub**
- **DevNexus**
- **InfraPilot**
- **PulseOps**
- **OpsDeck**
- **Project Nexus**
- **LaunchGrid**

### 1.2 One-Line Pitch

A futuristic all-in-one command center for developers, product owners, and engineering teams to monitor projects, repositories, infrastructure, deployments, website performance, and operational risk from one beautiful dashboard.

### 1.3 Main Goal

Build a centralized hub that connects to external and internal systems and transforms scattered operational data into one visual, actionable, real-time dashboard.

The system should answer questions like:

- What projects are active?
- Which repositories have open issues or pull requests?
- Which PRs are blocked, stale, failed, or ready to merge?
- Which deployments succeeded or failed?
- Which Docker hosts and containers are healthy?
- Which websites are slow or down?
- Which services are consuming too much CPU or memory?
- What changed in the last 24 hours?
- What areas of the system are most active?
- Which alerts require attention now?
- What products or systems need work first?

---

## 2. Target Users

### 2.1 Primary User

The primary user is a developer, technical lead, or product owner managing multiple products, services, repositories, and servers.

### 2.2 Secondary Users

- DevOps engineer
- SaaS founder
- Engineering manager
- IT support person
- Product manager
- Solo developer managing many projects
- Small company building internal tools

### 2.3 User Mindset

The user wants:

- Fast visibility
- A beautiful UX
- Fewer tabs open
- Less manual checking
- Better prioritization
- Better deployment confidence
- Better awareness of system health
- A premium dashboard that feels innovative and motivating

---

## 3. Core Product Principles

### 3.1 One Dashboard, Many Systems

The dashboard should not replace GitHub, Docker, uptime tools, or observability tools at first. It should aggregate their most important signals into one clear command center.

### 3.2 Signal Over Noise

Every widget should help the user make a decision.

Avoid vanity metrics unless they support action.

Good:

- Failed deployments
- High-priority issues
- Slow website response time
- Container memory pressure
- Stale pull requests
- Uptime drop
- Error spike
- Host down
- Service degraded

Less useful:

- Random chart without meaning
- Too many raw logs
- Too many counters without trend or context

### 3.3 Futuristic but Usable

The UI should feel futuristic, but not confusing.

Use:

- Dark mode
- Glassmorphism cards
- Neon accents
- Smooth transitions
- Map visualizations
- Heatmaps
- Timeline views
- Clear status badges
- Smart grouping
- Drill-down panels

Avoid:

- Tiny unreadable text
- Over-animation
- Too many colors without meaning
- Sci-fi visuals that make the product hard to use

### 3.4 Real-Time Where It Matters

Real-time updates should be used for:

- Alerts
- Deployment events
- Website status
- Container health
- Activity feed
- PR merge events
- Failed workflow events

Historical charts can update every few minutes.

### 3.5 Extensible Integrations

The app should be built with a plugin-like integration architecture so new providers can be added later.

Initial integrations:

- GitHub
- Docker Engine
- Website uptime/performance checks
- Manual/custom project records

Future integrations:

- GitLab
- Bitbucket
- Linear
- Jira
- Slack
- Discord
- Sentry
- Cloudflare
- AWS
- DigitalOcean
- Kubernetes
- Laravel Forge
- Ploi
- UptimeRobot
- Better Stack
- PostHog

---

## 4. Recommended Tech Stack

This specification assumes a stack that fits a Laravel + Vue full-stack developer workflow.

### 4.1 Backend

Use:

- **Laravel 12**
- **PHP 8.3+**
- **MySQL 8 or PostgreSQL**
- **Redis**
- **Laravel Horizon**
- **Laravel Scheduler**
- **Laravel Reverb or Pusher-compatible websockets**
- **Laravel Sanctum** for API/session authentication
- **Laravel Socialite** or GitHub OAuth app for GitHub account connection
- **Spatie Laravel Permission** for roles and permissions

### 4.2 Frontend

Use:

- **Vue 3**
- **TypeScript**
- **Inertia.js**
- **Tailwind CSS**
- **ShadCN-Vue / Reka UI style components**
- **Lucide Vue icons**
- **Chart library:** Apache ECharts, Chart.js, or Recharts-like Vue equivalent
- **Map library:** Mapbox GL, Leaflet, or a static SVG world map first
- **Heatmap:** custom CSS grid or calendar heatmap component

### 4.3 Realtime

Use:

- Laravel Reverb
- Echo client
- Event broadcasting
- Queued jobs for external sync
- Webhook ingestion for instant updates

### 4.4 Background Jobs

Use queues for:

- GitHub sync
- GitHub webhook processing
- Docker host polling
- Website uptime checks
- Performance probes
- Alert rule evaluation
- Metrics aggregation
- Daily summaries
- Cleanup jobs

### 4.5 Storage

Use the relational database for:

- Users
- Teams/accounts
- Projects
- Repositories
- GitHub issues
- GitHub pull requests
- Hosts
- Containers
- Deployments
- Alerts
- Metrics snapshots
- Events/activity feed

Use Redis for:

- Caching dashboard cards
- Rate limiting
- Short-lived locks
- Real-time status
- Queue backend

Use object/file storage later for:

- Screenshots
- Reports
- Export files
- Generated summaries

---

## 5. High-Level Architecture

### 5.1 System Layers

```text
Browser UI
   |
   | Inertia/Vue pages + Realtime events
   |
Laravel Application
   |
   | Controllers / Services / Actions
   |
Domain Modules
   |
   | GitHub Integration
   | Docker Integration
   | Uptime Monitoring
   | Alert Engine
   | Metrics Aggregator
   | Activity Feed
   |
Database + Redis + Queue
   |
External Systems
   |
   | GitHub API / Webhooks
   | Docker Engine API
   | Websites / HTTP probes
   | Future providers
```

### 5.2 Module Boundaries

Recommended backend modules:

```text
app/
    Domain/
        Projects/
        Repositories/
        GitHub/
        Docker/
        Monitoring/
        Alerts/
        Deployments/
        Activity/
        Dashboard/
        Integrations/
        Teams/
```

Each module should contain:

- Models
- Actions
- Services
- Data objects
- Query builders
- Policies
- Events
- Jobs
- Tests

Example:

```text
app/Domain/GitHub/
    Actions/
        SyncRepositoryIssuesAction.php
        SyncRepositoryPullRequestsAction.php
        HandleGitHubWebhookAction.php
    Data/
        GitHubIssueData.php
        GitHubPullRequestData.php
    Jobs/
        SyncGitHubRepositoryJob.php
        ProcessGitHubWebhookJob.php
    Services/
        GitHubClient.php
        GitHubRateLimitService.php
    Enums/
        GitHubEventType.php
```

---

## 6. Design Patterns to Use

### 6.1 Repository Pattern

Use repositories for complex read/write data access where queries are reused.

Example:

- `ProjectRepository`
- `DashboardMetricsRepository`
- `AlertRepository`
- `ActivityFeedRepository`

Do not overuse the pattern for simple CRUD. Use it where it improves clarity.

### 6.2 Action Classes

Use action classes for business operations.

Examples:

- `CreateProjectAction`
- `ConnectGitHubRepositoryAction`
- `SyncRepositoryIssuesAction`
- `EvaluateAlertRulesAction`
- `CreateActivityEventAction`
- `RecordWebsitePerformanceSnapshotAction`

Action classes should:

- Do one thing
- Be testable
- Accept clear DTOs or typed arguments
- Return a useful result object when needed

### 6.3 Service Classes

Use services for external APIs or reusable domain logic.

Examples:

- `GitHubClient`
- `DockerEngineClient`
- `WebsiteProbeService`
- `MetricAggregationService`
- `AlertNotificationService`

### 6.4 Data Transfer Objects

Use DTOs for external API payload normalization.

Examples:

```php
final readonly class GitHubIssueData
{
    public function __construct(
        public int $githubId,
        public int $number,
        public string $title,
        public string $state,
        public ?string $authorLogin,
        public array $labels,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $updatedAt,
        public ?CarbonInterface $closedAt,
    ) {}
}
```

### 6.5 Strategy Pattern

Use strategy classes for provider-specific behavior.

Examples:

```text
SourceProviderStrategy
    GitHubProviderStrategy
    GitLabProviderStrategy
    BitbucketProviderStrategy

HostProviderStrategy
    DockerEngineHostStrategy
    KubernetesHostStrategy
    ForgeHostStrategy
```

### 6.6 Adapter Pattern

Use adapters to convert external API responses into internal domain models.

Example:

```text
GitHubIssueAdapter
DockerContainerStatsAdapter
WebsiteProbeResultAdapter
```

### 6.7 Observer / Event Pattern

Use events for internal system reactions.

Examples:

- `PullRequestMerged`
- `DeploymentFailed`
- `ContainerBecameUnhealthy`
- `WebsiteBecameSlow`
- `AlertTriggered`
- `AlertResolved`

### 6.8 Specification Pattern for Alerts

Alert rules should be flexible.

Example:

```text
AlertRule
    metric_key: website.response_time_ms
    operator: >
    threshold: 1000
    duration_minutes: 5
    severity: warning
```

Use rule evaluators:

```text
ResponseTimeRuleEvaluator
UptimeRuleEvaluator
ContainerMemoryRuleEvaluator
DeploymentFailureRuleEvaluator
```

### 6.9 CQRS-Light

Use separate query objects for complex dashboard reads.

Examples:

- `GetOverviewDashboardQuery`
- `GetProjectHealthQuery`
- `GetRepositoryRiskQuery`
- `GetActivityHeatmapQuery`

Do not implement full CQRS unless the project becomes very large.

### 6.10 Cache-Aside Pattern

Dashboard data should be cached with short TTLs.

Examples:

- Overview metrics: 30 seconds
- Charts: 1-5 minutes
- Repository summaries: 1-5 minutes
- Host stats: 10-30 seconds
- Heatmap: 5-15 minutes

---

## 7. UX Direction

### 7.1 Visual Identity

The UI should feel like:

- A next-generation engineering platform
- A sci-fi command center
- A polished SaaS dashboard
- A developer cockpit
- Clean, modern, and premium

### 7.2 Theme

Default theme:

- Dark mode first
- Navy / black backgrounds
- Blue, cyan, purple, magenta neon accents
- Green for healthy/success
- Amber for warning
- Red/pink for danger
- Soft gradients
- Subtle borders
- Glassmorphism cards
- Glow effects only on key states

### 7.3 Color Tokens

Example Tailwind-style tokens:

```text
background.base: #020617
background.panel: rgba(15, 23, 42, 0.72)
background.panelHover: rgba(30, 41, 59, 0.85)
border.subtle: rgba(148, 163, 184, 0.16)
border.active: rgba(56, 189, 248, 0.5)

text.primary: #F8FAFC
text.secondary: #CBD5E1
text.muted: #64748B

accent.blue: #38BDF8
accent.cyan: #22D3EE
accent.purple: #8B5CF6
accent.magenta: #D946EF

status.success: #22C55E
status.warning: #F59E0B
status.danger: #EF4444
status.info: #3B82F6
```

### 7.4 Typography

Use a modern sans-serif font.

Recommended:

- Inter
- Geist
- SF Pro
- Manrope

Optional for numbers:

- JetBrains Mono
- IBM Plex Mono

Use monospaced font for:

- Commit hashes
- Host names
- Container IDs
- Metric values
- API keys
- Logs

### 7.5 Layout

Desktop layout:

```text
Left Sidebar       Main Dashboard Grid          Right Activity Rail
240px              Flexible                     320px
```

Responsive behavior:

- Large desktop: full sidebar + main grid + right activity rail
- Laptop: sidebar + main grid, activity rail collapses into drawer
- Tablet: sidebar becomes icon-only or drawer
- Mobile: stacked cards, bottom navigation, no dense map by default

### 7.6 Navigation Structure

Main sidebar:

1. Overview
2. Projects
3. Repositories
4. Issues & PRs
5. Pipelines
6. Deployments
7. Hosts
8. Monitoring
9. Analytics
10. Alerts
11. Settings

Optional future sections:

- Automations
- Incidents
- Reports
- AI Assistant
- Integrations Marketplace

### 7.7 Interaction Style

Use:

- Hover glow on interactive cards
- Smooth expand/collapse
- Slide-over panels for details
- Command palette
- Keyboard shortcuts
- Context menus
- Filter chips
- Timeline zoom
- Search-first navigation

Avoid:

- Full page reloads
- Large modals for everything
- Too many nested tabs
- Hidden critical actions

### 7.8 UX Pattern: Dashboard Card

All dashboard cards should have:

- Title
- Icon
- Primary metric
- Secondary label
- Trend
- Status color
- Optional mini sparkline
- Click action to drill down

Example:

```text
[Icon] Deployments
24
Successful
↑ 18%
Sparkline
```

### 7.9 UX Pattern: Drill-Down Drawer

When clicking a metric card, open a right-side drawer.

Example:

Click "Alerts 3" opens:

- Active alerts
- Severity
- Affected service
- Start time
- Last detected
- Suggested action
- Resolve / mute / view logs

### 7.10 UX Pattern: Command Palette

Implement a global command palette:

Shortcut:

```text
Cmd + K / Ctrl + K
```

Commands:

- Search project
- Search repository
- Open host
- Open alert
- Create project
- Connect GitHub
- Run sync
- View failed deployments
- View slow websites
- Toggle theme

### 7.11 UX Pattern: Empty States

Every section needs a strong empty state.

Example for no GitHub connection:

```text
Connect GitHub
Bring issues, pull requests, workflows, and repository activity into Nexus.
[Connect GitHub Account]
```

Example for no hosts:

```text
No Docker hosts yet
Add your first Docker host to monitor containers, CPU, memory, and uptime.
[Add Docker Host]
```

### 7.12 UX Pattern: Loading States

Use skeleton cards.

For dashboard:

- KPI skeleton cards
- Map skeleton shimmer
- Chart skeleton
- Activity feed skeleton
- Heatmap skeleton

### 7.13 UX Pattern: Error States

Show clear, actionable messages.

Example:

```text
GitHub sync failed
Reason: API rate limit exceeded.
Next retry: 12 minutes.
[View Integration Logs] [Retry Now]
```

---

## 8. Core Sections

---

# 8.1 Overview Dashboard

## Purpose

The Overview page is the main command center. It should summarize the entire system in one screen.

## Main Widgets

### 8.1.1 KPI Cards

Cards:

- Projects
- Deployments
- Services
- Hosts
- Alerts
- Uptime

Each card should show:

- Count
- Status
- Trend
- Mini sparkline
- Click to detail

Example data:

```json
{
    "projects": {
        "active": 12,
        "new_this_week": 2
    },
    "deployments": {
        "successful_24h": 24,
        "change_percent": 18
    },
    "services": {
        "running": 47,
        "health_percent": 100
    },
    "hosts": {
        "online": 128,
        "new": 4
    },
    "alerts": {
        "active": 3,
        "critical": 1
    },
    "uptime": {
        "overall": 99.98,
        "change": 0.01
    }
}
```

## AI Coding Agent Instructions

Build the Overview page first as the main proof of concept.

Create:

```text
resources/js/Pages/Overview/Index.vue
resources/js/Components/Dashboard/KpiCard.vue
resources/js/Components/Dashboard/Sparkline.vue
resources/js/Components/Dashboard/StatusBadge.vue
resources/js/Components/Dashboard/GlassCard.vue
```

Backend:

```text
app/Domain/Dashboard/Queries/GetOverviewDashboardQuery.php
app/Http/Controllers/OverviewController.php
```

Route:

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/overview', OverviewController::class)->name('overview');
});
```

Return all overview data in a single Inertia response.

---

# 8.2 Projects

## Purpose

Projects are the top-level business/product containers.

A project can have:

- Repositories
- Websites
- Hosts
- Services
- Deployments
- Alerts
- Notes
- Owner/team
- Status
- Priority

## Project Fields

```text
id
team_id
name
slug
description
status
priority
environment
owner_user_id
color
icon
health_score
last_activity_at
created_at
updated_at
```

## Status Options

```text
active
maintenance
paused
archived
```

## Priority Options

```text
low
medium
high
critical
```

## UX Requirements

Projects index should show:

- Project name
- Description
- Health score
- Linked repositories count
- Open issues count
- Open PR count
- Active alerts count
- Last deployment
- Last activity
- Owner
- Status badge

## Views

```text
/projects
/projects/create
/projects/{project}
```

Project detail tabs:

1. Overview
2. Repositories
3. Deployments
4. Hosts
5. Monitoring
6. Activity
7. Settings

## AI Coding Agent Instructions

Create CRUD for projects.

Files:

```text
app/Models/Project.php
app/Http/Controllers/ProjectController.php
app/Domain/Projects/Actions/CreateProjectAction.php
app/Domain/Projects/Actions/UpdateProjectAction.php
resources/js/Pages/Projects/Index.vue
resources/js/Pages/Projects/Create.vue
resources/js/Pages/Projects/Show.vue
resources/js/Pages/Projects/Edit.vue
```

Use policies:

```text
app/Policies/ProjectPolicy.php
```

---

# 8.3 Repositories

## Purpose

Repositories represent GitHub repositories linked to projects.

## Repository Fields

```text
id
project_id
provider
provider_id
owner
name
full_name
html_url
default_branch
visibility
language
description
stars_count
forks_count
open_issues_count
open_prs_count
last_pushed_at
last_synced_at
sync_status
created_at
updated_at
```

## Repository UX

Repositories index should show:

- Repository name
- Linked project
- Default branch
- Language
- Open issues
- Open PRs
- Last push
- Sync status
- Risk badge

Risk score should consider:

- Failed workflow runs
- Open high-priority issues
- Stale PRs
- Recent failed deployments
- Old last sync
- Too many unresolved alerts

## AI Coding Agent Instructions

Create repository model and GitHub sync foundation.

Files:

```text
app/Models/Repository.php
app/Domain/Repositories/Actions/LinkRepositoryToProjectAction.php
app/Domain/GitHub/Services/GitHubClient.php
app/Domain/GitHub/Jobs/SyncGitHubRepositoryJob.php
resources/js/Pages/Repositories/Index.vue
resources/js/Pages/Repositories/Show.vue
```

---

# 8.4 GitHub Issues & Pull Requests

## Purpose

Display GitHub issues and pull requests in a unified engineering work queue.

## Important Behavior

GitHub treats pull requests as issue-like objects in some issue endpoints. Internally, store issues and pull requests in separate tables or use a unified work item table with a type field.

Recommended approach:

- `github_issues` table for issues
- `github_pull_requests` table for pull requests
- `repository_work_items` view/query layer for combined UI

## GitHub Issue Fields

```text
id
repository_id
github_id
number
title
body_preview
state
author_login
labels_json
assignees_json
milestone_json
comments_count
priority
is_locked
created_at_github
updated_at_github
closed_at_github
synced_at
created_at
updated_at
```

## GitHub Pull Request Fields

```text
id
repository_id
github_id
number
title
body_preview
state
author_login
base_branch
head_branch
draft
mergeable
merged
merged_at
review_status
checks_status
additions
deletions
changed_files
comments_count
review_comments_count
created_at_github
updated_at_github
closed_at_github
synced_at
created_at
updated_at
```

## PR Status Logic

Use derived statuses:

```text
draft
open
needs_review
changes_requested
approved
checks_failed
merge_conflict
ready_to_merge
merged
closed
stale
```

## Issue Priority Logic

Priority can be determined by:

- Labels: `critical`, `high`, `bug`, `security`
- Repository importance
- Age
- Number of comments
- Manual override
- Project priority

## UX Requirements

Issues & PR page should include:

- Tabs: Issues / Pull Requests / All
- Filter by project
- Filter by repository
- Filter by label
- Filter by assignee
- Filter by status
- Search by title/number
- Sort by priority, age, updated date, status
- Badges for priority and state
- Link to GitHub
- Local notes field later

## AI Coding Agent Instructions

Create:

```text
resources/js/Pages/WorkItems/Index.vue
resources/js/Components/WorkItems/WorkItemTable.vue
resources/js/Components/WorkItems/WorkItemFilters.vue
resources/js/Components/WorkItems/PriorityBadge.vue
resources/js/Components/WorkItems/PullRequestStatusBadge.vue
```

Backend:

```text
app/Domain/GitHub/Actions/SyncRepositoryIssuesAction.php
app/Domain/GitHub/Actions/SyncRepositoryPullRequestsAction.php
app/Domain/GitHub/Actions/NormalizeGitHubIssueAction.php
app/Domain/GitHub/Actions/NormalizeGitHubPullRequestAction.php
```

---

# 8.5 GitHub Webhooks

## Purpose

Webhooks allow the system to receive events in near real-time instead of only polling.

## Supported Events

Start with:

```text
issues
pull_request
pull_request_review
push
workflow_run
check_suite
check_run
deployment
deployment_status
release
repository
```

## Webhook Security

Every webhook must verify the GitHub signature.

Store webhook deliveries:

```text
github_webhook_deliveries
    id
    github_delivery_id
    event
    action
    repository_full_name
    payload_json
    signature
    status
    error_message
    received_at
    processed_at
    created_at
    updated_at
```

## Processing Flow

```text
GitHub sends webhook
    |
WebhookController verifies signature
    |
Store raw delivery
    |
Dispatch ProcessGitHubWebhookJob
    |
Route event to handler
    |
Update database
    |
Create activity event
    |
Broadcast UI update if needed
```

## AI Coding Agent Instructions

Create:

```text
app/Http/Controllers/Webhooks/GitHubWebhookController.php
app/Domain/GitHub/Jobs/ProcessGitHubWebhookJob.php
app/Domain/GitHub/Actions/VerifyGitHubWebhookSignatureAction.php
app/Domain/GitHub/WebhookHandlers/IssuesWebhookHandler.php
app/Domain/GitHub/WebhookHandlers/PullRequestWebhookHandler.php
app/Domain/GitHub/WebhookHandlers/WorkflowRunWebhookHandler.php
app/Domain/GitHub/WebhookHandlers/PushWebhookHandler.php
```

Route:

```php
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->name('webhooks.github');
```

Important:

- Do not process webhook synchronously.
- Always return quickly.
- Queue heavy processing.
- Log unknown events.
- Do not fail silently.

---

# 8.6 Deployments & Pipelines

## Purpose

Show deployment history and CI/CD pipeline health.

## Deployment Sources

Initial sources:

- GitHub Actions workflow runs
- GitHub deployment events
- Manual deployment records
- Future: Forge/Ploi/GitHub Deployments/Kubernetes

## Deployment Fields

```text
id
project_id
repository_id
provider
provider_id
environment
commit_sha
branch
status
conclusion
actor_login
workflow_name
run_number
started_at
completed_at
duration_seconds
html_url
created_at
updated_at
```

## Deployment Statuses

```text
queued
in_progress
success
failed
cancelled
skipped
timed_out
unknown
```

## UX Requirements

Deployments page should show:

- Timeline
- Environment filters
- Status filters
- Repository filters
- Actor filters
- Duration
- Branch
- Commit
- Link to GitHub workflow
- Failed deployment card
- Deployment frequency chart
- Success rate card

## Overview Timeline

The dashboard timeline should show:

- Latest deployment events
- Success/failure markers
- Environment label
- Service/repository name
- Time
- Click to detail

## AI Coding Agent Instructions

Create:

```text
app/Models/Deployment.php
app/Domain/Deployments/Actions/RecordDeploymentAction.php
app/Domain/Deployments/Queries/GetDeploymentTimelineQuery.php
resources/js/Pages/Deployments/Index.vue
resources/js/Components/Deployments/DeploymentTimeline.vue
resources/js/Components/Deployments/DeploymentStatusBadge.vue
```

---

# 8.7 Docker Hosts

## Purpose

Monitor Docker hosts and containers.

## Host Fields

```text
id
project_id
name
slug
provider
endpoint_url
connection_type
status
last_seen_at
cpu_count
memory_total_mb
disk_total_gb
os
docker_version
metadata_json
created_at
updated_at
```

## Connection Types

```text
agent
ssh
docker_api
manual
```

## Recommended Approach

For security, do not connect directly to exposed Docker Engine APIs over the public internet.

Use a lightweight agent installed on each host.

The agent should:

- Collect host metrics
- Collect container metrics
- Send data to Nexus API
- Avoid requiring inbound public Docker API access
- Use a signed token
- Run as a Docker container or systemd service

## Agent MVP

Agent language options:

- Go
- Python
- Node.js

Recommended for later production: Go.

For MVP: PHP/Laravel endpoint receiving JSON data from a small Node/Python script is acceptable.

## Host Metrics

Collect:

- CPU usage
- Memory usage
- Disk usage
- Load average
- Network in/out
- Docker version
- Container count
- Running containers
- Unhealthy containers
- Host uptime

## Container Fields

```text
id
host_id
project_id
container_id
name
image
image_tag
status
state
health_status
ports_json
labels_json
cpu_percent
memory_usage_mb
memory_limit_mb
memory_percent
network_rx_bytes
network_tx_bytes
block_read_bytes
block_write_bytes
started_at
finished_at
last_seen_at
created_at
updated_at
```

## UX Requirements

Hosts page should show:

- Host cards
- CPU and memory bars
- Status badges
- Running container count
- Warning state
- Last seen
- Drill-down container list
- Container stats chart
- Host event log

## AI Coding Agent Instructions

Create:

```text
app/Models/Host.php
app/Models/Container.php
app/Models/HostMetricSnapshot.php
app/Models/ContainerMetricSnapshot.php
app/Http/Controllers/HostController.php
app/Http/Controllers/Agent/HostTelemetryController.php
app/Domain/Docker/Actions/IngestHostTelemetryAction.php
app/Domain/Docker/Actions/UpdateContainerSnapshotAction.php
resources/js/Pages/Hosts/Index.vue
resources/js/Pages/Hosts/Show.vue
resources/js/Components/Hosts/HostCard.vue
resources/js/Components/Hosts/ContainerTable.vue
```

Agent endpoint:

```php
Route::post('/agent/telemetry', HostTelemetryController::class)
    ->middleware(['agent.auth'])
    ->name('agent.telemetry');
```

---

# 8.8 Website Performance Monitoring

## Purpose

Monitor website uptime, response time, and load speed.

## Website Fields

```text
id
project_id
name
url
method
expected_status_code
timeout_ms
check_interval_seconds
status
last_checked_at
last_success_at
last_failure_at
created_at
updated_at
```

## Website Check Fields

```text
id
website_id
status
http_status_code
response_time_ms
dns_time_ms
connect_time_ms
tls_time_ms
ttfb_ms
content_length
error_message
checked_at
created_at
updated_at
```

## MVP Check

Start simple:

- HTTP GET
- Expected status 200
- Timeout 10 seconds
- Record response time
- Mark up/down

Later:

- DNS timing
- TLS timing
- TTFB
- Full page load
- Lighthouse integration
- Region-based checks
- Screenshot comparison

## UX Requirements

Monitoring page should show:

- Website name
- Current status
- Uptime %
- Response time
- Last checked
- 24h chart
- 7d chart
- Incidents
- SLA target

## Overview Widget

Website Performance card should show:

- Load time
- Uptime
- Average response time
- Traffic if available
- Trend lines

## AI Coding Agent Instructions

Create:

```text
app/Models/Website.php
app/Models/WebsiteCheck.php
app/Domain/Monitoring/Jobs/RunWebsiteCheckJob.php
app/Domain/Monitoring/Actions/RunWebsiteProbeAction.php
app/Domain/Monitoring/Actions/RecordWebsiteCheckAction.php
app/Domain/Monitoring/Queries/GetWebsitePerformanceSummaryQuery.php
resources/js/Pages/Monitoring/Websites/Index.vue
resources/js/Pages/Monitoring/Websites/Show.vue
resources/js/Components/Monitoring/WebsiteStatusCard.vue
resources/js/Components/Monitoring/ResponseTimeChart.vue
```

Scheduler:

```php
Schedule::job(new DispatchDueWebsiteChecksJob())->everyMinute();
```

---

# 8.9 Global Infrastructure Map

## Purpose

Show global infrastructure, servers, services, or website regions on a map.

## MVP

Use static nodes.

Data:

```text
region
label
latitude
longitude
latency_ms
status
request_count
error_rate
```

## Future

Add:

- Real region-based probes
- CDN regions
- User traffic locations
- Deployment regions
- Cloud provider regions
- Clickable region details

## UX Requirements

Map should show:

- Dark world map
- Glowing nodes
- Arcs between regions
- Region legend
- Latency values
- Status colors
- Summary metrics under map

## AI Coding Agent Instructions

Create:

```text
resources/js/Components/Maps/InfrastructureMap.vue
resources/js/Components/Maps/MapNode.vue
resources/js/Components/Maps/RegionLegend.vue
```

Use SVG first for simplicity.

Later replace with Mapbox/Leaflet when dynamic geospatial features are required.

---

# 8.10 Activity Feed

## Purpose

The activity feed is the real-time event stream of everything important.

## Event Types

```text
issue.created
issue.closed
pull_request.opened
pull_request.merged
pull_request.review_requested
workflow.failed
workflow.succeeded
deployment.started
deployment.succeeded
deployment.failed
website.down
website.recovered
host.offline
host.recovered
container.unhealthy
alert.triggered
alert.resolved
```

## Activity Event Fields

```text
id
team_id
project_id
repository_id
actor_user_id
source
event_type
severity
title
description
metadata_json
occurred_at
created_at
updated_at
```

## UX Requirements

Activity feed should show:

- Icon
- Title
- Description
- Time ago
- Severity color
- Source
- Click to detail
- Filter by project/type/severity

## AI Coding Agent Instructions

Create:

```text
app/Models/ActivityEvent.php
app/Domain/Activity/Actions/CreateActivityEventAction.php
app/Domain/Activity/Queries/GetRecentActivityQuery.php
resources/js/Components/Activity/ActivityFeed.vue
resources/js/Components/Activity/ActivityFeedItem.vue
```

Broadcast event:

```text
ActivityEventCreated
```

---

# 8.11 Heatmap Activity

## Purpose

Show intensity of project, repository, deployment, issue, or alert activity over time.

## Heatmap Types

- GitHub activity
- Deployment activity
- Alert activity
- Website incidents
- Container warnings
- Project activity
- User activity

## MVP Heatmap

Use a 7-day by 24-hour grid.

Rows:

```text
12 AM
4 AM
8 AM
12 PM
4 PM
8 PM
```

Columns:

```text
S M T W T F S
```

Each cell intensity is based on event count.

## Data Query

```text
event_count grouped by day_of_week and hour_bucket
```

## UX Requirements

- Low to high intensity legend
- Hover tooltip
- Click cell to filter activity feed
- Color gradient using purple/magenta/blue
- Works in dark mode

## AI Coding Agent Instructions

Create:

```text
app/Domain/Activity/Queries/GetActivityHeatmapQuery.php
resources/js/Components/Activity/ActivityHeatmap.vue
```

---

# 8.12 Alerts

## Purpose

Alerts help users focus on what needs attention.

## Alert Fields

```text
id
team_id
project_id
source
source_id
type
severity
status
title
description
triggered_at
resolved_at
last_seen_at
metadata_json
created_at
updated_at
```

## Alert Statuses

```text
open
acknowledged
resolved
muted
```

## Severities

```text
info
warning
critical
```

## Alert Sources

```text
github
docker
website
deployment
manual
system
```

## Example Alerts

```text
High memory usage on host-04.prod
Website response time above 1000ms
Deployment failed for payment-service
PR has merge conflicts
Workflow failed on main branch
Container unhealthy
Host offline
GitHub sync failed
```

## UX Requirements

Alerts page should include:

- Open alerts count
- Critical alerts count
- Filters by severity/source/project
- Alert timeline
- Acknowledge button
- Resolve button
- Mute button
- Link to affected entity
- Suggested action

## AI Coding Agent Instructions

Create:

```text
app/Models/Alert.php
app/Domain/Alerts/Actions/TriggerAlertAction.php
app/Domain/Alerts/Actions/ResolveAlertAction.php
app/Domain/Alerts/Actions/AcknowledgeAlertAction.php
app/Domain/Alerts/Jobs/EvaluateAlertRulesJob.php
resources/js/Pages/Alerts/Index.vue
resources/js/Components/Alerts/AlertCard.vue
resources/js/Components/Alerts/AlertSeverityBadge.vue
```

---

# 8.13 Analytics

## Purpose

Analytics helps users understand trends and system performance over time.

## Initial Analytics

- Deployment success rate
- Deployment frequency
- Failed deployment trend
- Mean time to recovery
- Open issues trend
- PR cycle time
- Stale PR count
- Average website response time
- Uptime trend
- Container resource usage trend
- Alert frequency

## UX Requirements

Analytics page should include:

- Date range picker
- Project filter
- Repository filter
- Metric cards
- Line charts
- Bar charts
- Heatmaps
- Export to CSV later

## AI Coding Agent Instructions

Create:

```text
resources/js/Pages/Analytics/Index.vue
app/Domain/Analytics/Queries/GetAnalyticsOverviewQuery.php
```

---

# 8.14 Settings & Integrations

## Purpose

Settings should allow the user to connect and configure external systems.

## Settings Sections

1. Profile
2. Team
3. Projects
4. GitHub Integration
5. Docker Hosts / Agents
6. Website Monitors
7. Alert Rules
8. API Tokens
9. Billing later
10. Audit Logs

## GitHub Settings

Show:

- Connected GitHub account
- Installation status
- Selected repositories
- Webhook status
- Last sync
- Sync now button
- Disconnect button

## Docker Agent Settings

Show:

- Agent install command
- Agent token
- Connected hosts
- Last seen
- Rotate token button

## AI Coding Agent Instructions

Create:

```text
resources/js/Pages/Settings/Index.vue
resources/js/Pages/Settings/Integrations/GitHub.vue
resources/js/Pages/Settings/Integrations/Docker.vue
resources/js/Pages/Settings/Monitoring/Websites.vue
resources/js/Pages/Settings/Alerts/Rules.vue
```

---

## 9. Database Roadmap

### 9.1 Core Tables

Create migrations in this order:

```text
teams
team_user
projects
repositories
github_issues
github_pull_requests
github_webhook_deliveries
deployments
hosts
containers
host_metric_snapshots
container_metric_snapshots
websites
website_checks
activity_events
alerts
alert_rules
integration_connections
api_tokens
audit_logs
```

### 9.2 Team Model

The app should support teams/accounts from the start.

Even if the first version is single-user, add `team_id` now to avoid painful refactors.

### 9.3 Integration Connections Table

Use this table for external provider credentials and settings.

```text
id
team_id
provider
name
status
credentials_encrypted_json
settings_json
last_connected_at
last_synced_at
created_at
updated_at
```

Providers:

```text
github
docker
website_monitor
slack
discord
sentry
```

Important:

- Encrypt tokens.
- Never expose secrets in Inertia props.
- Support token rotation later.

---

## 10. Backend API / Controller Design

### 10.1 Controller Style

Keep controllers thin.

Controllers should:

- Validate request
- Authorize action
- Call action/query class
- Return Inertia page or JSON

Controllers should not contain heavy business logic.

### 10.2 Example Controller

```php
final class OverviewController
{
    public function __invoke(GetOverviewDashboardQuery $query): Response
    {
        return Inertia::render('Overview/Index', [
            'dashboard' => $query->handle(auth()->user()->currentTeam),
        ]);
    }
}
```

### 10.3 API Endpoint Groups

Web app routes:

```text
/overview
/projects
/repositories
/work-items
/deployments
/hosts
/monitoring
/analytics
/alerts
/settings
```

Webhook routes:

```text
/webhooks/github
```

Agent routes:

```text
/agent/telemetry
```

Internal JSON routes:

```text
/api/dashboard/overview
/api/activity/recent
/api/alerts/open
/api/hosts/{host}/metrics
/api/websites/{website}/checks
```

---

## 11. Frontend Component Architecture

### 11.1 Recommended Folder Structure

```text
resources/js/
    Components/
        App/
            AppLayout.vue
            Sidebar.vue
            TopBar.vue
            RightActivityRail.vue
            CommandPalette.vue
        Dashboard/
            GlassCard.vue
            KpiCard.vue
            Sparkline.vue
            MetricTrend.vue
            StatusBadge.vue
        Charts/
            LineChart.vue
            BarChart.vue
            MiniSparkline.vue
            ResourceUtilizationChart.vue
        Activity/
            ActivityFeed.vue
            ActivityFeedItem.vue
            ActivityHeatmap.vue
        Alerts/
            AlertCard.vue
            AlertSeverityBadge.vue
        Hosts/
            HostCard.vue
            ContainerTable.vue
            ResourceBar.vue
        Repositories/
            RepositoryCard.vue
            RepositoryRiskBadge.vue
        WorkItems/
            WorkItemTable.vue
            WorkItemFilters.vue
            PriorityBadge.vue
        Maps/
            InfrastructureMap.vue
        UI/
            Button.vue
            Input.vue
            Select.vue
            Badge.vue
            Drawer.vue
            Modal.vue
            Tooltip.vue
            Dropdown.vue
    Pages/
        Overview/
        Projects/
        Repositories/
        WorkItems/
        Deployments/
        Hosts/
        Monitoring/
        Analytics/
        Alerts/
        Settings/
```

### 11.2 Component Rules

Every component should:

- Use TypeScript props
- Have clear loading state support
- Have empty state support where relevant
- Use design tokens / Tailwind classes consistently
- Avoid hardcoded business data except in demo mode
- Be responsive

### 11.3 App Layout

`AppLayout.vue` should include:

- Sidebar
- Top bar
- Main content container
- Optional right rail
- Global command palette
- Global toast notifications

---

## 12. Dashboard Grid System

### 12.1 Desktop Grid

Use CSS grid.

Example:

```text
grid-cols-12 gap-4
```

Suggested layout:

```text
KPI cards: full width row
Issues panel: col-span-4
Map panel: col-span-5
Performance panel: col-span-3
Hosts panel: col-span-3
Service health: col-span-2
Resource utilization: col-span-3
Top repositories: col-span-2
Heatmap: col-span-2
Timeline: col-span-6
System metrics: col-span-6
```

### 12.2 Responsive Rules

- On small screens, every widget becomes full width.
- Right activity rail becomes a button/drawer.
- Map becomes simplified card.
- Heatmap becomes horizontally scrollable.
- Tables become card lists.

---

## 13. Data Refresh Strategy

### 13.1 Polling

Use polling for non-critical metrics.

Examples:

- Overview cards: every 30 seconds
- Website charts: every 60 seconds
- Host metrics: every 10-15 seconds
- Repository counts: every 5 minutes

### 13.2 WebSockets

Use websockets for:

- New activity event
- New alert
- Alert resolved
- Deployment status changed
- Website down/recovered
- Host offline/recovered

### 13.3 Cache Strategy

Create dashboard cache keys:

```text
team:{team_id}:dashboard:overview
team:{team_id}:activity:recent
team:{team_id}:alerts:open
project:{project_id}:health
repository:{repository_id}:summary
host:{host_id}:latest_metrics
```

Use tag-based cache if possible.

---

## 14. Health Score System

### 14.1 Purpose

A health score helps summarize project risk.

Score range:

```text
0 - 100
```

### 14.2 Suggested Formula

Start with 100 points.

Subtract:

```text
-30 for critical active alert
-15 for warning active alert
-20 for failed deployment in last 24h
-10 for stale PR over 7 days
-10 for website response time above threshold
-20 for website down
-15 for host offline
-10 for container unhealthy
-5 for failed GitHub sync
```

Clamp between 0 and 100.

### 14.3 Labels

```text
90-100 healthy
70-89 good
50-69 degraded
30-49 warning
0-29 critical
```

### 14.4 UX

Use:

- Ring chart
- Badge
- Color
- Tooltip explaining why score changed

---

## 15. AI Assistant Feature

### 15.1 Purpose

Later, add an internal AI assistant that can summarize system health.

Example prompts:

- “What needs my attention today?”
- “Summarize failed deployments from last 24 hours.”
- “Which PRs are blocking releases?”
- “Why is the WMS project health score low?”
- “Create a weekly engineering report.”

### 15.2 MVP

Do not build AI in phase 1.

Prepare data endpoints and summaries first.

### 15.3 Future AI Output

AI assistant should use internal data only unless explicitly configured.

It should cite:

- Activity events
- Alerts
- Deployments
- Issues
- PRs
- Website checks

---

## 16. Security Requirements

### 16.1 Authentication

Use Laravel auth scaffolding or existing starter kit.

Support:

- Login
- Register
- Forgot password
- Email verification
- Team membership

### 16.2 Authorization

Use policies and permissions.

Roles:

```text
owner
admin
developer
viewer
```

Permissions:

```text
manage projects
manage integrations
view dashboards
manage alerts
manage hosts
manage repositories
manage team
```

### 16.3 Secrets

Store tokens encrypted.

Never log:

- GitHub tokens
- Webhook secrets
- Agent tokens
- API keys

### 16.4 Webhook Security

Verify signatures.

Reject invalid signatures.

Store delivery metadata.

### 16.5 Agent Security

Agent telemetry endpoint must require:

- Bearer token
- Token hash stored in DB
- Optional host fingerprint
- Rate limit
- IP allowlist later

### 16.6 Rate Limiting

Rate limit:

- Webhooks by IP/provider
- Agent telemetry by token
- Manual sync buttons by user
- API endpoints

---

## 17. Observability for Nexus Itself

Nexus should monitor itself.

Track:

- Queue failures
- Job duration
- Sync errors
- API rate limits
- Webhook failures
- Agent ingestion failures
- Website check failures
- Database performance

Create internal system alerts:

- Queue backlog too high
- GitHub rate limit almost exhausted
- Webhook failures increasing
- Agent token invalid attempts
- Scheduled checks delayed

---

## 18. Error Handling

### 18.1 External API Errors

Every integration should gracefully handle:

- Rate limits
- Invalid token
- Unauthorized
- Not found
- Timeout
- API schema changes
- Partial data

### 18.2 Sync Statuses

Use:

```text
pending
syncing
success
failed
rate_limited
unauthorized
disabled
```

### 18.3 Retry Strategy

Use exponential backoff.

Examples:

- GitHub rate limit: retry after reset time
- Website check timeout: retry next scheduled interval
- Docker telemetry missing: mark stale after threshold
- Webhook job failure: retry 3 times, then mark failed

---

## 19. Development Phases

---

# Phase 0 — Product Foundation

## Goal

Create the foundation project with authentication, layout, theme, and seed demo dashboard.

## Deliverables

- Laravel + Vue + Inertia project
- Auth
- Dark futuristic layout
- Sidebar
- Top bar
- Overview page with static/demo data
- Reusable dashboard components
- Design tokens
- Responsive structure

## Pages

- Login
- Register
- Overview
- Settings placeholder

## Components

- AppLayout
- Sidebar
- TopBar
- GlassCard
- KpiCard
- Sparkline
- StatusBadge
- ActivityFeed
- ActivityHeatmap

## Acceptance Criteria

- User can log in
- User can see futuristic dashboard
- Dashboard matches the generated concept visually
- Cards are responsive
- No real integrations required yet

---

# Phase 1 — Projects & Repositories

## Goal

Add project management and repository records.

## Deliverables

- Projects CRUD
- Repository CRUD/linking
- Project detail page
- Repository index
- Project health placeholder
- Seed sample projects/repos

## Acceptance Criteria

- User can create a project
- User can link repository manually
- Project dashboard shows repository count
- Overview cards use database data instead of static data

---

# Phase 2 — GitHub Integration MVP

## Goal

Connect GitHub and sync issues/pull requests.

## Deliverables

- GitHub OAuth or GitHub App connection
- Repository selection
- Sync repository metadata
- Sync issues
- Sync pull requests
- Work Items page
- Manual sync button

## Acceptance Criteria

- User can connect GitHub
- User can select repositories
- Issues sync into local DB
- PRs sync into local DB
- Work Items page shows real data
- GitHub links open correctly
- Sync errors are visible

---

# Phase 3 — GitHub Webhooks & Activity Feed

## Goal

Make GitHub updates near real-time.

## Deliverables

- GitHub webhook endpoint
- Signature verification
- Webhook delivery storage
- Handlers for issues, pull requests, workflow runs, push
- Activity events
- Real-time UI activity feed

## Acceptance Criteria

- New GitHub issue creates activity event
- PR opened/merged updates local data
- Failed workflow creates deployment/activity event
- Invalid webhook signatures are rejected
- UI updates without page refresh

---

# Phase 4 — Deployments & CI/CD

## Goal

Display workflow runs and deployment timeline.

## Deliverables

- Deployment model
- GitHub Actions workflow sync
- Deployment timeline component
- Deployment detail drawer
- Deployment success rate chart

## Acceptance Criteria

- Dashboard shows latest deployments
- Failed deployment appears as alert/activity
- Timeline shows success/failure markers
- User can filter by project/repository/environment

---

# Phase 5 — Website Monitoring

## Goal

Monitor websites for uptime and response time.

## Deliverables

- Website monitors CRUD
- Scheduled website checks
- Response time recording
- Uptime calculation
- Website performance widget
- Website detail page

## Acceptance Criteria

- User can add website URL
- System checks URL every configured interval
- Uptime % is calculated
- Slow/down site triggers alert
- Performance widget uses real data

---

# Phase 6 — Docker Host Agent MVP

## Goal

Monitor Docker hosts and containers.

## Deliverables

- Host model
- Container model
- Agent token system
- Telemetry ingestion endpoint
- Basic agent script
- Hosts page
- Container metrics display

## Acceptance Criteria

- User can create host/agent token
- Agent can send telemetry
- Host appears online
- Containers appear with CPU/memory
- Host offline triggers alert after timeout

---

# Phase 7 — Alerts Engine

## Goal

Centralize warnings and critical problems.

## Deliverables

- Alert model
- Alert rules
- Alert evaluation jobs
- Alert page
- Acknowledge/resolve/mute actions
- Alert real-time updates

## Acceptance Criteria

- Website down creates alert
- Failed deployment creates alert
- Host offline creates alert
- User can acknowledge alert
- Resolved issue closes alert

---

# Phase 8 — Analytics & Health Scores

## Goal

Add deeper insights and trend analysis.

## Deliverables

- Health score calculation
- Analytics dashboard
- Deployment frequency chart
- PR cycle time chart
- Alert frequency chart
- Uptime trend chart
- Activity heatmap powered by real data

## Acceptance Criteria

- Project health score reflects real system signals
- Analytics page supports date ranges
- Heatmap shows real activity
- Dashboard prioritizes risky projects

---

# Phase 9 — Polish & Production Readiness

## Goal

Prepare for daily use.

## Deliverables

- Full responsive polish
- Loading states
- Error states
- Empty states
- Tests
- Audit logs
- API rate limit handling
- Installation docs
- Deployment docs
- Backup plan
- Monitoring Nexus itself

## Acceptance Criteria

- App is stable
- Errors are visible and actionable
- Core flows have tests
- Sensitive tokens are encrypted
- Background jobs are reliable
- UI feels premium

---

# Phase 10 — Future Innovation

## Goal

Make Nexus feel like a truly innovative platform.

## Future Features

- AI daily briefing
- AI incident summary
- AI PR risk score
- AI project health explanation
- Global command palette
- Custom dashboard builder
- Widget marketplace
- Team reports
- Slack/Discord notifications
- Mobile app
- Kubernetes support
- Cloud provider integrations
- Synthetic browser checks
- Screenshot monitoring
- Incident management
- Status page generator

---

## 20. MVP Scope Recommendation

The best MVP should include:

1. Auth
2. Futuristic dashboard layout
3. Projects
4. Repositories
5. GitHub issues and PR sync
6. Activity feed
7. Website monitoring
8. Basic alerts

Do not start with every feature.

The strongest first version is:

```text
Overview + Projects + GitHub Issues/PRs + Website Monitoring + Alerts
```

Docker host monitoring can be Phase 2 or Phase 3 depending on complexity.

---

## 21. Detailed AI Coding Agent Build Order

Give the coding agent these steps in sequence.

### Step 1 — Create Laravel Project

Create a new Laravel project with:

- Laravel 12
- Vue 3
- Inertia
- TypeScript
- Tailwind CSS
- Auth
- Redis
- Queue
- Scheduler
- Horizon

### Step 2 — Build Base Layout

Create:

- `AppLayout.vue`
- `Sidebar.vue`
- `TopBar.vue`
- `RightActivityRail.vue`
- `CommandPalette.vue`
- Dark theme CSS variables
- Glassmorphism utility classes

### Step 3 — Build Static Overview

Create the dashboard with mock data.

Include:

- KPI cards
- Issues & PR card
- Map card
- Website performance card
- Container hosts card
- Service health card
- Resource utilization chart
- Top repositories card
- Activity feed
- Heatmap
- Deployment timeline
- System metrics

### Step 4 — Add Database Models

Create migrations/models for:

- Teams
- Projects
- Repositories
- Issues
- Pull requests
- Activity events
- Alerts

### Step 5 — Connect Dashboard to Database

Replace mock data with query classes.

### Step 6 — Add Projects CRUD

Build full project management.

### Step 7 — Add GitHub Integration

Implement GitHub connection, repository selection, sync jobs.

### Step 8 — Add Issues & PR UI

Build filterable issue/PR work queue.

### Step 9 — Add Activity Feed

Create activity events and realtime updates.

### Step 10 — Add Website Monitoring

Create website monitors, checks, uptime, alerts.

### Step 11 — Add Alerts Engine

Trigger, display, acknowledge, and resolve alerts.

### Step 12 — Add Docker Host Monitoring

Build agent/token approach and host UI.

### Step 13 — Add Analytics

Build trends and health scores.

### Step 14 — Production Polish

Add tests, docs, error handling, security, deployment scripts.

---

## 22. Visual Design Specification

### 22.1 Card Style

Cards should use:

```text
rounded-2xl
bg-slate-950/70
border border-slate-700/40
shadow-2xl
backdrop-blur-xl
hover:border-cyan-400/40
transition
```

### 22.2 Glow Effects

Use glow sparingly.

Examples:

- Active sidebar item
- Critical alert
- Online status dot
- Map node
- Primary CTA

### 22.3 Sidebar Style

Sidebar should include:

- Logo
- Product name
- Navigation
- User card
- System status card
- Mini stats

### 22.4 Top Bar Style

Top bar should include:

- Page title
- Global search
- Add button
- Notifications
- Time range selector
- Theme toggle

### 22.5 Status Colors

```text
healthy: green
success: green
warning: amber
danger: red/pink
info: cyan/blue
neutral: slate
```

### 22.6 Icons

Use Lucide icons:

```text
LayoutDashboard
FolderKanban
GitPullRequest
GitBranch
Rocket
Server
Activity
BarChart3
Bell
Settings
ShieldCheck
Globe
Box
Database
Cpu
MemoryStick
AlertTriangle
CheckCircle2
XCircle
```

---

## 23. Routes

Recommended web routes:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => redirect()->route('overview'));

    Route::get('/overview', OverviewController::class)->name('overview');

    Route::resource('projects', ProjectController::class);
    Route::resource('repositories', RepositoryController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('/work-items', WorkItemController::class)->name('work-items.index');

    Route::get('/deployments', DeploymentController::class)->name('deployments.index');

    Route::resource('hosts', HostController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::resource('websites', WebsiteController::class);
    });

    Route::get('/analytics', AnalyticsController::class)->name('analytics.index');

    Route::resource('alerts', AlertController::class)->only(['index', 'show']);
    Route::post('/alerts/{alert}/acknowledge', AcknowledgeAlertController::class)->name('alerts.acknowledge');
    Route::post('/alerts/{alert}/resolve', ResolveAlertController::class)->name('alerts.resolve');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', SettingsController::class)->name('index');
        Route::get('/integrations/github', GitHubIntegrationController::class)->name('integrations.github');
        Route::get('/integrations/docker', DockerIntegrationController::class)->name('integrations.docker');
    });
});

Route::post('/webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

Route::post('/agent/telemetry', HostTelemetryController::class)
    ->middleware(['agent.auth'])
    ->name('agent.telemetry');
```

---

## 24. Testing Strategy

### 24.1 Backend Tests

Test:

- Project creation
- Repository linking
- GitHub sync normalization
- Webhook signature validation
- Webhook event processing
- Website check recording
- Alert triggering
- Alert resolving
- Activity event creation
- Dashboard query output

### 24.2 Frontend Tests

Test:

- Dashboard renders cards
- Filters work
- Empty states appear
- Error states appear
- Drawer opens
- Command palette opens

### 24.3 Integration Tests

Test:

- GitHub webhook payload processing
- Agent telemetry ingestion
- Website check job
- Realtime activity event broadcast

---

## 25. Seed Data

Create a demo seeder that generates:

- 12 projects
- 20 repositories
- 128 issues
- 32 PRs
- 24 deployments
- 4 hosts
- 30 containers
- 10 websites
- 50 activity events
- 3 active alerts
- 7 days of activity heatmap data
- 24 hours of website performance data

This lets the UI look impressive before real integrations are connected.

Seeder:

```text
database/seeders/DemoDashboardSeeder.php
```

Command:

```bash
php artisan db:seed --class=DemoDashboardSeeder
```

---

## 26. Production Deployment Notes

### 26.1 Required Services

Production needs:

- Web server
- PHP-FPM
- Database
- Redis
- Queue worker
- Scheduler
- Websocket server
- HTTPS
- Backup strategy

### 26.2 Laravel Workers

Run:

```bash
php artisan queue:work
php artisan horizon
php artisan schedule:work
php artisan reverb:start
```

Use Supervisor or systemd.

### 26.3 Environment Variables

```env
APP_NAME="Nexus Control Center"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nexus.example.com

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_WEBHOOK_SECRET=

AGENT_SHARED_SECRET=
```

---

## 27. AI Coding Agent Prompt

Use this prompt when starting implementation:

```text
You are building "Nexus Control Center", a futuristic Laravel 12 + Vue 3 + Inertia + TailwindCSS engineering operations dashboard.

The goal is to create an all-in-one command center for projects, GitHub repositories, issues, pull requests, deployments, Docker hosts, website monitoring, alerts, activity heatmaps, maps, and analytics.

Use a modular architecture with Laravel domain folders:
app/Domain/Projects
app/Domain/Repositories
app/Domain/GitHub
app/Domain/Docker
app/Domain/Monitoring
app/Domain/Alerts
app/Domain/Deployments
app/Domain/Activity
app/Domain/Dashboard

Use action classes for business logic, query classes for dashboard reads, service classes for external APIs, DTOs for API payloads, and policies for authorization.

Frontend must use Vue 3 with TypeScript, Inertia, TailwindCSS, reusable components, dark mode, glassmorphism cards, neon blue/cyan/purple accents, responsive design, skeleton loading states, empty states, and polished UX.

Start with Phase 0:
- Create authentication
- Create AppLayout
- Create Sidebar
- Create TopBar
- Create Overview page
- Create reusable dashboard cards
- Create static/mock dashboard data
- Match the futuristic dashboard concept visually

Then move phase by phase:
1. Projects & repositories
2. GitHub integration
3. Issues & PRs
4. GitHub webhooks and activity feed
5. Deployments
6. Website monitoring
7. Docker host agent
8. Alerts engine
9. Analytics and health scores
10. Production polish

Always write full updated files.
Use 4 spaces for indentation.
Follow Laravel Pint formatting.
Keep controllers thin.
Move business logic into actions/services.
Use TypeScript props on Vue components.
Make the UI beautiful, responsive, and production-quality.
```

---

## 28. Definition of Done

The project is considered complete for version 1 when:

- User can log in
- User can create projects
- User can connect GitHub
- User can sync repositories
- User can view issues and PRs
- User can see real activity feed
- User can monitor websites
- User can receive alerts
- User can see deployment timeline
- User can see Docker host/container metrics
- Dashboard feels futuristic and premium
- Core pages are responsive
- Tokens are secure
- Background jobs work
- Tests cover core flows
- Documentation exists

---

## 29. Final Product Experience

When the user opens Nexus Control Center, they should immediately feel:

> “I can see my entire engineering world from here.”

The dashboard should provide:

- Awareness
- Confidence
- Prioritization
- Speed
- Beauty
- Control

The final product should feel like a high-end engineering cockpit for modern software teams.
