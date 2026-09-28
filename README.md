# Kiln

Self-hosted server management, deployment and observability — a replacement for Laravel Forge + Envoyer
(multi-server deploys) + Coolify-style Docker/native builds + Nightwatch-style APM, on an LGTM stack.

| Part | What it is |
|---|---|
| `control-plane/` | Laravel 12 **modular monolith** (17 modules under `modules/`) + Inertia React TypeScript UI |
| `agent/` | Go: `kiln-agent` (servers, ~10 MB, ~17 MB RAM), `kiln` (CLI), `kiln-builder` (build worker) |
| `packages/apm-laravel` | `kiln/apm-laravel` — Nightwatch-equivalent instrumentation (Laravel 11/12/13) |
| `packages/apm-node` | `@kiln/apm-node` — OpenTelemetry preset for Node/Bun/Deno/TypeScript apps |
| `observability/` | Loki + Tempo + Grafana + VictoriaMetrics **or** Mimir, dashboards, alert rules |
| `contracts/` | Agent protocol + telemetry contract (JSON Schemas shared by PHP and Go) |
| `sim/` | Local end-to-end simulation: control plane + 3 Ubuntu 24.04 "servers" + builder + git + LGTM |
| `docs/` | `API.md` (public REST API), `INTEGRATION-NOTES.md` (cross-module decisions, known limits) |

Architecture and module boundaries: [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## 1. Try everything locally (simulation)

Requirements: Docker (with ~8 GB RAM for Docker), Go 1.25, `make`, `jq`, `curl`, `openssl`.

```bash
cd agent && make build && cd ..          # agent, CLI and builder binaries (mounted into the sim)
cd sim
make up                                   # builds + starts everything, waits until healthy (~1 min once images are built)
make e2e                                  # infrastructure checks (TLS, mTLS edge, observability, servers)
make e2e-deploy                           # full product flow (below), ~13 min cold, less warm; ONLY=deploy,release to iterate
```

`make e2e-deploy` drives the product **only through public surfaces** — `artisan kiln:admin`, the REST
API, and the install command each server prints:

1. creates the first admin, organization and API token;
2. creates 2 app servers + 1 database server via the API, runs their install commands, and waits for
   **real provisioning** on Ubuntu 24.04 (FrankenPHP, PHP 8.4, Node, PostgreSQL, nftables firewall, SSH hardening);
3. connects a git server, creates a Laravel 13 site on both app servers (app-1 = leader);
4. deploys it: one native build → fetch + prepare on both → migrations on the leader only → activation →
   health checks; then a second commit (zero-downtime), a rollback, and a broken release that must be
   **rolled back automatically**;
5. serves a Laravel site through **Octane** (FrankenPHP worker mode behind the edge): the placeholder stays up until
   the first deploy, a redeploy and switching Octane off happen with zero failed requests;
6. deploys a Bun + Hono **TypeScript** site (runtime installed on demand, supervised web process);
7. checks observability: APM traces in Tempo, the demo exception grouped into an Insights issue,
   deployment lifecycle events in Loki.

Useful while it runs:

| | |
|---|---|
| Control plane UI | `https://localhost:8443` (trust `sim/.data/edge-root.crt`, created by `make ca`) — log in as `admin@kiln.test` after setting a password: `cd sim && docker compose -p kiln-sim --env-file sim.env --env-file .data/secrets.env exec control-plane php artisan kiln:admin admin@kiln.test --reset-password --password='choose-one'` |
| Grafana | `http://localhost:13000` (admin / admin) |
| Logs | `make logs` · `make shell` |
| Reset | `make reset` (wipes all volumes and generated secrets, keeps the package/image/download caches) · `make reset-all` (also wipes the caches) |
| Timings | each E2E stage prints its duration; summary table at the end, history in `sim/.data/e2e-timings.tsv` |

Ports differ from the defaults (13000, 14318, 8443, …) to avoid clashing with other stacks; see `sim/sim.env`.

---

## 2. Run the tests

```bash
# Control plane (unit, feature, architecture/boundary tests) + quality gates
cd control-plane
composer install && bun install
cp .env.example .env && php artisan key:generate
./vendor/bin/pest
vendor/bin/pint --test && bun run types && bun run lint:check && bun run format:check && bun run build

# Agent, CLI, builder
cd ../agent && go vet ./... && go test -race ./... && make build

# APM packages
cd ../packages/apm-laravel && composer install && vendor/bin/pest
cd ../apm-node && bun install && bun run typecheck && bun test

# Observability stack smoke test (both metrics backends)
cd ../../observability && ./smoke-test.sh --stack all
```

CI runs the same in `.github/workflows/` (control-plane, agent, packages with a Laravel 11/12/13 matrix).

---

## 3. Use it

### First run
```bash
php artisan migrate --force
php artisan kiln:admin you@example.com --organization="Acme" --token=cli   # prints password + API token
php artisan kiln:admin you@example.com --reset-password                    # lost password
```
Then open the UI, or use the CLI / API.

### Add a server
UI: **Servers → Create** (Hetzner, DigitalOcean, Vultr, Linode, AWS Lightsail, or *Custom*).
For *Custom*, run the printed one-line install command as root on a fresh Ubuntu 24.04 box. The agent
enrolls over mTLS, then the server is provisioned automatically for its type (app, web, db, cache,
worker, load balancer, builder).

### Create and deploy a site
UI: **Sites → Create** — pick server(s) (first = leader for migrations), framework preset
(Laravel, Symfony, Statamic, WordPress, PHP, Next, Nuxt, Node, static, Docker), runtime
(FrankenPHP default, php-fpm, Node, Bun, Deno, static, Docker), repository and branch.
Then **Deploy**, or enable push-to-deploy, or call the deploy hook URL from CI.
Laravel sites can switch on **Octane** under Settings → Laravel (FrankenPHP worker mode on FrankenPHP servers, Swoole or
RoadRunner on PHP-FPM): Kiln picks a free port per server, and Caddy serves `public/` files itself and proxies the rest
to Octane once it answers; deploys restart Octane while Caddy holds requests.

### CLI (`agent/bin/kiln-<os>-<arch>`)
```bash
kiln login --url https://kiln.example.com          # or KILN_URL / KILN_TOKEN in CI
kiln servers list
kiln sites list
kiln deploy shop --wait                            # streams output; exit code 3 if the deploy fails
kiln releases shop
kiln rollback shop --wait
kiln env pull shop > .env.production && kiln env push shop < .env.production
kiln logs shop --follow
kiln ssh app-1
```

### API
Sanctum bearer tokens scoped to one organization. Endpoints and payloads: [`docs/API.md`](docs/API.md).

### Instrument your apps (APM)
- Laravel: require `kiln/apm-laravel` — auto-discovered; sends requests, queries, jobs, mail,
  notifications, cache, commands, scheduled tasks, outgoing HTTP, exceptions and logs to the local agent.
- Node/Bun/Deno: `import '@kiln/apm-node/register'` (see `packages/apm-node/README.md`).

Issues, thresholds and missed scheduled runs appear under **Insights**; traces, logs and metrics in
Grafana (dashboards are provisioned per organization).

---

## 4. Production install

One command on a fresh Ubuntu 22.04/24.04 or Debian 12 host (4 GB RAM recommended). Point DNS for
`kiln.example.com` and `agents.kiln.example.com` at the host first:

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/kiln/main/deploy/install.sh \
  | sudo bash -s -- --domain kiln.example.com --email you@example.com [--observability]
```

The installer runs preflight checks, installs Docker, generates `/opt/kiln/.env`, pulls the release images
from GHCR, starts the Compose stack (`deploy/compose.yml`: FrankenPHP web, Horizon, Reverb, scheduler,
Postgres 17, Valkey, a Caddy edge with Let's Encrypt and agent mTLS, and the builder) and prints the first
admin password. Day-2 operations use `kiln-ctl`: `status`, `logs`, `update` (backup, then automatic
rollback if the update fails), `backup`/`restore` (database, storage, **Fleet CA**, `.env`), `doctor`,
`domain set` and `admin reset-password`.

Full guide (requirements, DNS, upgrade, backup/restore, uninstall, troubleshooting, releasing):
[`docs/INSTALL.md`](docs/INSTALL.md).

Known limits and deferred items are listed in [`docs/INTEGRATION-NOTES.md`](docs/INTEGRATION-NOTES.md).
