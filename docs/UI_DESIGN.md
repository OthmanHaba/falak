# Kiln UI — Railway-style redesign (binding spec)

Goal: replace the stock shadcn look with a Railway-grade product. Dark-first (plus a matching light theme and
"system"), canvas-centric, calm, fast, keyboard-friendly. Every page in every module moves into the model below.

---

## 1. UX principles (Railway)

1. **The project canvas is home.** Users think in *projects* and *services*, not menus. Infrastructure (servers)
   is visible but secondary.
2. **Context stays put.** Opening a service slides a panel over the canvas; the canvas never navigates away.
   Deep links (`/projects/{p}/{env}/service/{id}/{tab}`) restore exactly that view.
3. **Live by default.** Status dots, deploy progress, logs and metrics update in place (Reverb, polling fallback).
   Never ask the user to refresh.
4. **One primary action per surface** (e.g. *Deploy*, *Create*), everything else in `⋯` menus or ⌘K.
5. **Changes are explicit.** Config edits that need a deploy show a sticky bar: *"3 changes — Deploy to apply"*.
6. **Keyboard first.** ⌘K everywhere; `g p` projects, `g s` servers, `g o` observability; `Esc` closes panels;
   `[`/`]` previous/next tab inside a panel.
7. **Quiet density.** Small type, generous spacing, few borders, no heavy cards-in-cards. Color only for status.
8. **Empty states teach.** Every empty list explains what it is and offers the one action that fills it.
9. **Destructive actions** require typing the resource name; everything else is undoable or instant.

---

## 2. Design tokens

Defined once in `resources/css/app.css` as CSS variables, mapped into Tailwind 4 `@theme`. **No hard-coded colors
in components.**

### Color — dark (default)
| Token | Value | Use |
|---|---|---|
| `--bg` | `#0b0a0f` | app background |
| `--bg-canvas` | `#0e0d13` | canvas (dotted grid `--grid` `#1d1b26`, 1px dots every 20px) |
| `--surface-1` | `#13121a` | panels, cards |
| `--surface-2` | `#1a1823` | hover, inputs, nested |
| `--surface-3` | `#23202e` | active, selected |
| `--border` | `#26232f` | hairlines (1px) |
| `--border-strong` | `#35313f` | focused inputs, selected cards |
| `--text` | `#ecebf2` | primary text |
| `--text-muted` | `#a19eb0` | secondary |
| `--text-faint` | `#6d6a7c` | tertiary, placeholders |
| `--accent` | `#8b5cf6` | primary buttons, focus ring, links (violet) |
| `--accent-hover` | `#7c4ddf` | |
| `--accent-soft` | `rgba(139,92,246,.14)` | selected nav, soft badges |
| `--success` | `#22c55e` · soft `rgba(34,197,94,.14)` | active/healthy |
| `--warning` | `#f59e0b` · soft | building/deploying/degraded |
| `--danger` | `#ef4444` · soft | failed/crashed/offline |
| `--info` | `#38bdf8` · soft | queued/informational |

### Color — light
`--bg #fbfbfc`, `--bg-canvas #f6f6f8` (grid `#e4e3ea`), `--surface-1 #ffffff`, `--surface-2 #f4f3f7`,
`--surface-3 #ebe9f1`, `--border #e7e5ee`, `--border-strong #d4d1de`, `--text #17151f`, `--text-muted #5d5a6b`,
`--text-faint #8e8b9c`; accent/status identical hues (accent `#7c3aed` for contrast).

### Type
- UI: **Inter** (variable, `@fontsource-variable/inter`), code/logs/ids: **JetBrains Mono**
  (`@fontsource-variable/jetbrains-mono`).
- Scale: 11 (meta) · 12 (labels, table) · 13 (body, default) · 14 (panel titles) · 16 (page titles) · 20 (hero).
  Weights 400/500/600 only. Tabular numerals for metrics.

### Shape, space, motion
- Radius: 6 (inputs, buttons), 8 (cards), 12 (panels, dialogs), full (status dots, avatars).
- Spacing base 4px; page gutter 24px (16px on mobile).
- Elevation: panels `0 8px 32px rgba(0,0,0,.45)` (dark) / `0 8px 24px rgba(20,16,40,.08)` (light); no other shadows.
- Motion: 150ms ease-out (hover/press), 220ms cubic-bezier(.2,.8,.2,1) (panel slide, dialog); respect
  `prefers-reduced-motion`.
- Focus: 2px `--accent` ring with 2px offset on every interactive element.

### Status language (one component: `<StatusDot>` + `<StatusBadge>`)
| State | Color | Pulse | Label |
|---|---|---|---|
| active / online / healthy / succeeded | success | – | Active / Online / Healthy / Success |
| building / deploying / provisioning / queued-running | warning | yes | Building / Deploying / Provisioning |
| queued / waiting | info | – | Queued |
| failed / crashed / offline / error | danger | – | Failed / Crashed / Offline |
| removed / inactive / skipped / cancelled | faint | – | Removed / Inactive / Cancelled |

---

## 3. Information architecture

```
Top bar:  [Kiln ◆] [Org ▾] / [Project ▾] / [Environment ▾]      [⌘K Search]   [🔔] [Help] [Avatar ▾]
```
No permanent sidebar. Top-level destinations (also in ⌘K and the org menu):

| Route | Page | Replaces |
|---|---|---|
| `/` → `/projects` | **Projects** grid (cards: name, env count, service icons, last deploy, status) | `dashboard.tsx` |
| `/projects/{p}/{env}` | **Project canvas** (§4) | Sites/Index, Databases/Index (per project) |
| `/projects/{p}/{env}/service/{kind}/{id}/{tab?}` | canvas + **Service panel** (§5) | Sites/*, Deployments/*, Edge/*, Processes/*, Databases/Show, Insights per site |
| `/projects/{p}/settings` | Project settings (name, environments, members access, danger) | – |
| `/servers` | **Infrastructure**: server fleet table + map of which services run where | Servers/Index |
| `/servers/{id}/{tab?}` | **Server panel** page: Overview, Metrics, Processes, Firewall, Network, Terminal, SSH keys, Recipes, PHP, Settings | Servers/Show, Network/*, Terminal/*, Recipes/* (per server) |
| `/observability` | **Observability**: Overview · Issues · Traces · Logs · Heartbeats · Alerts | Insights/*, Telemetry/*, Alerting/* |
| `/observability/issues/{id}` | Issue detail | Insights/Issue |
| `/settings/{section}` | **Account & org settings**, left-nav inside the page: Profile, Password, 2FA, Appearance, API tokens · Org: General, Members, Teams, Audit log, Source control, Cloud providers, Storage (backups), Builders, Alert channels, Observability, Recipes library | Identity/*, SourceControl, Providers, Databases/Storage, Builds/Builders, Alerting/Channels+Rules, Telemetry/Settings, Recipes/Index |
| `/notifications` | Notification center (also a popover from 🔔) | Alerting/Notifications |
| auth pages | centered minimal card on `--bg` with subtle grid | Identity/auth/* |

Legacy URLs (`/sites/{id}`, `/servers`, …) **redirect** to the new locations so existing links, CLI `open`, alert
links and API `url` fields keep working.

---

## 4. Project canvas

- Full-bleed below the top bar; `@xyflow/react` with dotted background, pan (drag / space+drag), zoom
  (⌘ scroll, `+`/`-`, *fit* button), minimap off by default. Positions persist per environment (Projects module).
- **Service card** (240×~96): kind icon (framework logo for sites: Laravel, Next, Bun…; engine logo for databases),
  name, one-line status (`● Active · 2m ago` / `● Deploying 64%`), domain (sites) or engine+size (databases),
  server chips (`app-1 app-2`, leader starred). Hover lifts border; selected = `--border-strong` + accent glow.
- **Edges** (thin dashed lines) show references: a site whose variables reference a database (§5.3) is
  connected to it. Edges are derived, not drawn by hand.
- **Groups** (optional frames) — later; not in v1.
- **"+ Create"** (top-right of canvas, also `⌘K → Create`, also right-click canvas) opens the **Create picker**:
  - *Git repository* → pick connection → repo → branch → preset auto-detected → servers → **Deploy**.
  - *Docker image* (runtime docker) · *Empty service*
  - *Database* → engine (PostgreSQL / MySQL / MariaDB / Redis) → server → create.
  - *Template* (placeholder "Coming soon" — compose templates land in roadmap step 4).
  Creation happens **inline in the picker** (no separate page); the new card appears on the canvas and its panel
  opens on the Deployments tab with the first deploy streaming.
- Environment switcher: `production`, `staging`, … + *New environment* (empty, or *duplicate from* an existing
  environment: copies service configs and variables, not servers/targets — user picks servers per service).
- Canvas toolbar (bottom-left): zoom, fit, *Activity* toggle (right rail: recent deploys/events of this env).

## 5. Service panel

Right-anchored panel over the canvas, width `min(960px, 62vw)` (full-screen on < 1024px), resizable,
`Esc`/click-outside closes. Header: icon · name (inline rename) · status badge · primary action (**Deploy** for
sites, **Connect** for databases) · `⋯` (Redeploy, Rollback…, Restart processes, Open site ↗, Copy id, Delete).

### 5.1 Tabs — site
| Tab | Content (from module) |
|---|---|
| **Deployments** | Active deployment card on top (commit, author avatar, branch, age, duration, servers, *View logs*, `⋯` Rollback/Redeploy); below: history list; queued deployments shown stacked. Clicking opens **Deploy view** (§5.2). (Deployments) |
| **Variables** | Table (key, masked value with reveal-on-click (audited), row actions) + *New variable* inline row + **Raw editor** toggle (dotenv, monospace, diff before save) + *Expose to deploy script* per key + **reference picker** `${{ service.KEY }}` (§5.3). Shows "changes apply on next deploy" bar. (Sites env) |
| **Metrics** | CPU/mem/requests/p95/errors charts (recharts, 1h/6h/24h/7d), per server. (Telemetry) |
| **Logs** | Live log stream (Loki), search, level filter, server filter, pause/follow, click a line → trace. (Telemetry) |
| **Observability** | Issues for this site, slow routes/jobs/queries, heartbeats. (Insights) |
| **Processes** | Web process, queue workers, Horizon, Octane, daemons, cron jobs — one list with status + inline edit. (Processes) |
| **Settings** | Long scrolling page with anchored sections + left mini-nav: **Source** (repo/branch, push-to-deploy, deploy hook URL) · **Build** (mode, runtime, versions, build env prefixes) · **Deploy** (strategy, script editor with macros, retention, health check) · **Networking** (domains + TLS, test domain toggle, redirects, security rules, headers) · **Servers** (targets, leader, add/remove) · **Laravel** (scheduler/Horizon/Octane/maintenance) · **Commands** (run artisan/shell with live output) · **Danger** (delete). (Sites, Edge, Deployments settings) |

### 5.2 Deploy view
Full-height view inside the panel (back arrow to list). Header: status, commit, trigger, duration, *Redeploy*,
*Rollback*, *Cancel*. **Phase timeline** (Build → Fetch → Prepare → Migrate → Activate → Restart → Health) as a
horizontal stepper per server (rows = servers, cells = phase status with duration). Tabs **Build logs** /
**Deploy logs**: virtualized monospace log (JetBrains Mono 12px, ANSI colors, line numbers, sticky phase headers,
search, copy, download, auto-follow with "jump to live").

### 5.3 Variable references
`${{ <service-name>.<KEY> }}` in a site's variables resolves at deploy time to the referenced service's variable
in the **same environment** (database services expose `DATABASE_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, plus Redis `REDIS_URL`). Resolution is owned by Projects via a contract Sites calls
when rendering a release's `.env`; unresolved references fail the deploy with a clear error. References drive
canvas edges.

### 5.4 Tabs — database service
**Overview** (engine, server, connection strings with copy + reveal, private-network address) · **Databases &
users** · **Backups** (schedules, history, restore) · **Metrics** · **Settings**.

---

## 6. Components (`resources/js/components/kiln/`)

Build a small, owned component set (Radix primitives underneath are fine; **no stock shadcn styling**):
`AppShell`, `TopBar`, `OrgSwitcher`, `ProjectSwitcher`, `EnvironmentSwitcher`, `CommandPalette` (restyled,
grouped results, recent items, actions with shortcuts), `Panel` (slide-over with tabs + URL sync), `Tabs`,
`Button` (primary/secondary/ghost/danger, sizes sm/md), `IconButton`, `Input`, `Textarea`, `Select`, `Combobox`,
`Switch`, `Checkbox`, `Field` (label, hint, error), `Section` (settings section with title/description/aside),
`DataTable` (sortable, sticky header, row actions, empty/loading states), `StatusDot`, `StatusBadge`, `Tag`,
`Avatar`, `Tooltip`, `Menu` (`⋯`), `Dialog`, `ConfirmDestructive` (type-to-confirm), `Toast`, `EmptyState`,
`Skeleton`, `CodeBlock` (copy), `LogViewer` (virtualized), `MetricChart`, `KeyValue`, `Stepper`/`PhaseTimeline`,
`ChangesBar`, `CopyButton`, `RelativeTime` (live-updating), `ServiceIcon` (framework/engine logos),
`ServiceCard` (canvas), `EmptyCanvas`.

Rules: every list has loading skeleton + empty state; every async action shows pending state on the button and a
toast on completion/failure; every form validates inline (server 422 errors mapped to fields).

---

## 7. Backend additions required by the UX

1. **Projects module** (new): `projects`, `environments` (slug, is_production, forked_from), `project_services`
   (environment_id, kind `site|database`, ref_id, x, y). Every existing site/database is migrated into a
   per-org **"Default"** project, `production` environment. Contracts: `ProjectDirectory` (projectOf(kind,id),
   servicesIn(env), environment lookups), `VariableReferences` (resolve(envId, siteId, dotenv) → dotenv/errors).
   Events: `ProjectCreated`, `EnvironmentCreated`, `ServiceLinked`, `ServiceUnlinked`. Sites created elsewhere
   (API/CLI) are linked to the org's default project (listener on `SiteCreated`); the site/database create APIs
   accept optional `project_id` + `environment_id`.
2. **Sites** exposes a creation contract (`Sites\Contracts\SiteFactory::create/duplicate`) so Projects can create
   and fork services without reaching into Sites internals; release `.env` rendering calls
   `Projects\Contracts\VariableReferences`.
3. **Databases** exposes connection variables per database for references.
4. **Read models for the canvas**: one endpoint returns all services of an environment with live status (latest
   deployment, targets, domains, servers) to render the canvas in a single request.

---

## 8. Verification

- **Playwright** (`control-plane/tests/Browser`, run against the sim): logs in, visits **every route** in both
  themes, fails on console errors / failed requests / horizontal overflow at 1440px and 390px, and saves
  screenshots to `tests/Browser/screenshots/{theme}/{route}.png` for visual review.
- Existing Pest feature tests keep passing (update Inertia component names where pages moved).
- Lighthouse-style budgets: first canvas render < 1.5s on the sim with 20 services; route JS < 250 KB gzip.

---

## 9. Shared contracts between the UI foundation and the Projects backend (fixed; build against these)

**Inertia shared prop `kiln`** (added by the Projects module to every authenticated page, via a
`Kernel`-level shared-props registry so app glue doesn't import modules):
```ts
type KilnShared = {
  projects: { id: string; name: string; icon: string | null; environments: { id: string; name: string; slug: string; is_production: boolean }[] }[];
  current: { project_id: string | null; environment_id: string | null }; // from the URL or last visited
};
```

**Canvas read model** — `GET /projects/{project}/{environment}/canvas` (JSON, session auth):
```ts
type CanvasService = {
  id: string;                     // project_services.id
  kind: 'site' | 'database';
  ref_id: string;                 // site id / database id
  name: string;
  icon: string;                   // 'laravel' | 'next' | 'bun' | 'postgresql' | ... (ServiceIcon key)
  position: { x: number; y: number };
  status: 'active' | 'deploying' | 'building' | 'queued' | 'failed' | 'crashed' | 'inactive' | 'provisioning';
  status_label: string;           // "Active · 2m ago", "Deploying 64%"
  url: string | null;             // primary https URL (sites)
  subtitle: string | null;        // "PostgreSQL 17 · db-1"
  servers: { id: string; name: string; leader: boolean; online: boolean }[];
  last_deployment: { id: string; status: string; commit: string | null; message: string | null; finished_at: string | null } | null;
};
type Canvas = { services: CanvasService[]; edges: { from: string; to: string }[] };  // edges by project_services.id
```
`PATCH /projects/{project}/{environment}/services/{service}/position {x,y}` persists card positions.

**Page names** (Inertia): `Projects/Index`, `Projects/Canvas` (props: project, environment, canvas, `panel?: {kind, id, tab}`),
`Projects/Settings`. Service panel tab content is loaded as **JSON from each owning module's endpoints**
(the panel is one page; tabs fetch lazily), so modules keep owning their data and routes.
