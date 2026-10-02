# Installing Kiln (production)

Kiln runs as a Docker Compose stack on a single Linux host. The installer sets up Docker, writes a
generated configuration to `/opt/kiln/.env`, starts the stack, and creates the first administrator.
Day-2 operations use `kiln-ctl`.

```
internet ──:80/:443──► edge (Caddy)
                        ├─ kiln.example.com         Let's Encrypt ─► control-plane (FrankenPHP) · reverb (websockets)
                        ├─ agents.kiln.example.com  Fleet-CA cert + client-cert (mTLS) check ─► control-plane
                        ├─ registry.kiln.example.com Let's Encrypt + basic auth ─► registry (built-in image registry)
                        └─ grafana.kiln.example.com Let's Encrypt ─► grafana            (optional)
control-plane · horizon · reverb · scheduler ─► postgres 17 · valkey
builder (kiln-builder serve: PHP/Composer, Node, Bun) ─► edge
```

Releases are published from [github.com/OthmanHaba/kiln](https://github.com/OthmanHaba/kiln); a fork can install its own releases with `--repo OWNER/NAME`.

## 1. Requirements

| | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 / 24.04, Debian 12 (amd64 or arm64) | Ubuntu 24.04 |
| RAM | 2 GB (installer warns below 4 GB) | 4 GB; **8 GB with `--observability`** |
| Disk | 10 GB free | 25 GB+ (images, build artifacts, backups) |
| Network | public IPv4 (or IPv6); ports **80** and **443** free and reachable | |
| DNS | records for the panel, `agents.` and `registry.` hosts (below) | |

This host runs only Kiln. The servers Kiln manages are separate machines.

**Resource budget** (limits are caps, not reservations). Idle values were measured with three managed servers enrolled and a site deployed (sim) and on a 2-CPU host profile (bench):

| Service | Limit | Idle | Notes |
|---|---|---|---|
| control-plane (panel, FrankenPHP worker mode) | 512 MB | 130–150 MB | ~12 MB per booted worker (2 × CPUs), autoscales under load |
| agent-api (agents, installer, builders) | 512 MB | 70–100 MB | +2–3 MB per waiting long-poll (one per server and builder) |
| horizon (`KILN_HORIZON_MAX_PROCESSES`, default 4, auto-balanced from 1) | 512 MB | 130–150 MB | |
| reverb | 192 MB | 50 MB | |
| scheduler | 256 MB | 45 MB | a short `schedule:run` process every minute |
| postgres (`shared_buffers=128MB`) | 512 MB | 40–150 MB | agents' long-polls hold no connection |
| valkey (`maxmemory 128mb`, AOF) | 192 MB | 15 MB | |
| edge | 128 MB | 25–35 MB | |
| builder | 1.5 GB | 10–120 MB | builds use up to ~1 GB |
| **core total** | **≈ 4.3 GB** | **≈ 0.6–0.8 GB** | idle CPU: a few % of one core |
| observability: gateway, loki, tempo, victoriametrics, grafana | 64 + 384 + 384 + 384 + 512 MB | 20 + 75 + 75 + 65 + 320–360 MB (≈ 0.6 GB) | |

The scheduler stays a separate container. Running `schedule:work` inside Horizon would save only its ~45 MB
and would tie the two services' restarts and health together. Grafana 12+ downloads its Drilldown, Advisor
and Pyroscope apps on first start. Kiln does not use them, and they add about 110 MB, which put a fresh
Grafana at ~470 MB against its 512 MB limit. The stack therefore skips that step
(`KILN_GRAFANA_PREINSTALL_DISABLED=false` in `.env` restores it). On a 2-vCPU / 4 GB host the core stack
leaves more than 3 GB free. With `--observability`, plan for 8 GB so builds have room.

## 2. DNS

Create these records before you install (replace the IP with your server's public address):

| Name | Type | Value | Why |
|---|---|---|---|
| `kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | panel, installer script, agent enrollment |
| `agents.kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | agent API (mTLS) |
| `registry.kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | built-in image registry: docker builds push, servers pull |
| `grafana.kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | only with `--observability` |

- The panel, the registry and Grafana get **Let's Encrypt** certificates automatically (HTTP-01/TLS-ALPN on ports 80/443).
- The **registry** host serves Kiln's built-in image registry (Docker Distribution) behind basic auth: docker-mode
  builds (Dockerfile sites, compose services with `build:`) push there and servers pull from it, pinned by digest.
  The credentials are generated into `.env` (`KILN_REGISTRY_USERNAME`, `KILN_REGISTRY_PASSWORD`); Kiln hands them
  to builders and servers itself. With `--tls internal` its certificate is not trusted by Docker on other hosts,
  so docker builds need `--tls acme` (or the internal root trusted in each Docker daemon).
- The **agents** host does *not* use Let's Encrypt. Agents pin Kiln's own Fleet CA, so the edge serves
  that host with a certificate issued by the Fleet CA, and verifies agent client certificates against it.
  Behind Cloudflare, set all records to **DNS only** (grey cloud): a proxy would terminate TLS and break mTLS.
- The installer checks that every name resolves to this host's public IP, and it prints the missing records.
- **Installs from before the registry** get `KILN_REGISTRY_*` added to `.env` by the next `kiln-ctl up` or
  `kiln-ctl update` (an update run by an older kiln-ctl: run `kiln-ctl up` once afterwards). Add the
  `registry.` DNS record; `kiln-ctl registry status` checks it.

## 3. Install

```bash
curl -fsSL https://raw.githubusercontent.com/OthmanHaba/kiln/main/deploy/install.sh \
  | sudo bash -s -- --domain kiln.example.com --email you@example.com
```

Or pin a release with its own copy of the script:
`curl -fsSL https://github.com/OthmanHaba/kiln/releases/download/v1.2.3/install.sh | sudo bash -s -- --domain ... --email ...`

What it does:

1. **Preflight:** checks that you are root, the OS, the CPU architecture, RAM, disk, that ports 80/443 are free, and that DNS for the panel and `agents.` host (plus `grafana.` with `--observability`) points at this host's public IP.
2. Installs **Docker Engine** and the Compose plugin from Docker's official apt repository if they are missing.
3. Downloads the release bundle `kiln-deploy.tar.gz`, verifies it against `SHA256SUMS`, and unpacks it to
   `/opt/kiln/{deploy,observability}`. It also installs `kiln-ctl` to `/usr/local/bin`.
4. Generates `/opt/kiln/.env` (mode 600) with `APP_KEY`, database and Valkey passwords, Reverb keys, the
   builder token, the OTLP token, and all `KILN_*` URLs.
5. Pulls the images `ghcr.io/<owner>/kiln-{control-plane,builder,edge}:<version>`, starts the stack, and
   waits until every service is healthy. Database migrations run in the `control-plane` service on start.
6. Creates the first administrator with `kiln:admin` and **prints the password once**.

| Option | Env | Default |
|---|---|---|
| `--domain NAME` | `KILN_DOMAIN` | required |
| `--email ADDRESS` | `KILN_EMAIL` | required for Let's Encrypt; also the admin e-mail |
| `--admin-email ADDRESS` | `KILN_ADMIN_EMAIL` | `--email` |
| `--version TAG` | `KILN_VERSION` | latest release |
| `--observability` | `KILN_OBSERVABILITY=1` | off |
| `--tls acme\|internal` | `KILN_TLS` | `acme` (`internal` = Caddy's local CA, for testing only) |
| `--repo OWNER/NAME` | `KILN_REPO` | `OthmanHaba/kiln` |
| `--image-prefix PREFIX` | `KILN_IMAGE_PREFIX` | `ghcr.io/<owner>` |
| `--build-from-source [--ref REF]` | `KILN_BUILD_FROM_SOURCE=1` | clones the repo and builds images locally (no registry) |
| `--source-dir PATH` | `KILN_DEPLOY_SOURCE` | use deploy files from a local checkout |
| `--http-port/--https-port` | `KILN_HTTP_PORT/KILN_HTTPS_PORT` | 80/443 (other ports only with `--tls internal`) |
| `--skip-dns-check`, `--force` | | |

Running the installer again is safe. Secrets are kept, settings from the flags are updated, the stack is
converged, and the admin is not created a second time. To enable observability later, re-run it with
`--observability`. The installer then creates a Grafana service account token for Kiln
(`KILN_GRAFANA_TOKEN`). Grafana's `admin` password is `GRAFANA_ADMIN_PASSWORD` in `/opt/kiln/.env`.

**Who can sign up.** By default anyone who can reach the panel can create an account (and gets an empty
organization of their own). On a panel reachable from the internet, set `KILN_REGISTRATION` in `/opt/kiln/.env`
and run `kiln-ctl up`:

| Value | Sign-up |
|---|---|
| `open` (default) | anyone |
| `invite` | only through an invitation link (invite from **Settings → Members**): the person opens the e-mailed link, chooses *Sign up* and registers with the invited address, which also joins the organization |
| `closed` | nobody; the *Sign up* links are hidden. Create accounts with `kiln-ctl admin create <email>` |

An unknown value counts as `closed`. A panel without any account always accepts the first sign-up, so the first
administrator can register before the setting matters.

Next steps: log in, open **Servers → Create**, and run the printed install command on each server.
The agent binaries come from the control-plane image, so servers download them from your panel
(`/install/agent/linux-{amd64,arm64}`), not from GitHub.

### Files

```
/opt/kiln/.env            settings + secrets (install.sh; never commit or share)
/opt/kiln/custom.env      optional extra app env (GITHUB_APP_*, mirrors, KILN_* tuning) — loaded by the app containers
/opt/kiln/deploy/         compose.yml, kiln-ctl, image support files (replaced on update; previous kept as deploy.prev)
/opt/kiln/observability/  Loki/Tempo/Grafana/gateway configs
/opt/kiln/backups/        kiln-ctl backup output
```

**Which file?** `.env` holds the settings `deploy/compose.yml` passes to the containers by name (domains, secrets,
`MAIL_*`, sizing, telemetry, `KILN_REGISTRATION`, …) plus kiln-ctl's own (`KILN_BACKUP_*`, `KILN_PRUNE_IMAGES`).
Every other app variable — e.g. `GITHUB_APP_*`, `KILN_WEBHOOK_URL`, `KILN_*_MIRROR` — goes in `custom.env`;
compose does not forward it from `.env`. A variable compose passes by name always comes from `.env`: setting it in
`custom.env` has no effect.

For e-mail, set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and
`MAIL_FROM_ADDRESS` in `/opt/kiln/.env`, then run `kiln-ctl up`.

### Connect GitHub (GitHub App, one click)

Open **Settings → Source control → Connect GitHub**. Kiln registers a private GitHub App for your Kiln organization
(on your personal GitHub account, or on a GitHub organization you own: type its name) using GitHub's
[app manifest flow](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest):
you confirm the app on github.com, Kiln stores its id, private key and webhook secret **encrypted in its database**,
and GitHub continues straight to the installation page, where you pick the repositories Kiln may deploy. Change
that selection any time with **Manage access on GitHub**; GitHub sends you back to Kiln afterwards.

- **Access requested:** repository contents and metadata, **read-only**, plus `push` events. No deploy keys and no
  per-repository webhooks: builds clone over HTTPS with installation tokens minted per build (valid for one hour,
  cached for at most 50 minutes, never stored or logged).
- **Kiln's URL must be reachable from GitHub** for push-to-deploy: the app's single webhook is
  `https://<panel>/api/webhooks/source-control/github-app/<id>` (shown on the card). `KILN_WEBHOOK_URL` overrides the
  base URL (set it in `/opt/kiln/custom.env`) if GitHub must reach Kiln through a different host. Creating the app and cloning work without it; only
  push-triggered deploys and installation status updates (suspended / uninstalled on GitHub) need the webhook.
- **One app per Kiln organization.** GitHub only lets a private app be installed on the account that owns it, so
  repositories from a second GitHub account need their own app (another Kiln organization), or a token connection.
- **Remove:** *Disconnect* on an installation uninstalls the app from that account; *Delete app* uninstalls it
  everywhere and forgets the credentials. Delete the app registration itself on GitHub (App settings → Advanced).
- **Personal access tokens** still work (*Use a personal access token instead*), e.g. for GitHub Enterprise Server.

**Operator-managed app (optional).** To use one app you created yourself for every Kiln organization, set these in
`/opt/kiln/custom.env` (not `.env`: compose does not forward them from there) and run `kiln-ctl up`. When set, they take precedence over registered apps for new installations
(existing installations keep the app they were made with), and the one-click registration is hidden.

| Variable | Value |
|---|---|
| `GITHUB_APP_ID` | the app's numeric id |
| `GITHUB_APP_SLUG` | the app's URL name (`github.com/apps/<slug>`) |
| `GITHUB_APP_PRIVATE_KEY` | the PEM private key (newlines may be written as `\n`) |
| `GITHUB_APP_WEBHOOK_SECRET` | the webhook secret; webhook URL `https://<panel>/api/webhooks/source-control/github-app/env` |

Configure that app with Setup URL `https://<panel>/source-control/github-app/setup` ("Redirect on update" on),
permissions Contents: read and Metadata: read, and the `push` event.

### Runtime download mirrors (optional)

Servers download FrankenPHP, Node.js, Bun and Deno release binaries during provisioning (sha256-verified).
If servers cannot reach GitHub / nodejs.org, or you run a caching proxy in front of them, point the agents at
an HTTPS mirror with the same path layout. Set these in `/opt/kiln/custom.env`, then `kiln-ctl up`:

| Variable | Replaces | Fetched path |
|---|---|---|
| `KILN_FRANKENPHP_MIRROR` | `https://github.com/php/frankenphp/releases/download` | `<mirror>/v<version>/frankenphp-linux-<arch>` |
| `KILN_NODE_MIRROR` | `https://nodejs.org/dist` | `<mirror>/v<version>/SHASUMS256.txt`, `node-v<version>-linux-<arch>.tar.gz` |
| `KILN_BUN_MIRROR` | `https://github.com/oven-sh/bun/releases/download` | `<mirror>/bun-v<version>/SHASUMS256.txt`, `bun-linux-<arch>.zip` |
| `KILN_DENO_MIRROR` | `https://github.com/denoland/deno/releases/download` | `<mirror>/v<version>/deno-<arch>-unknown-linux-gnu.zip{,.sha256sum}` |

Unset (the default) means the upstream URLs. The mirror applies to servers provisioned (or runtimes
installed) after the change.

### Docker address ranges (optional)

Containers on an app or worker server (compose stacks, Docker sites, functions) reach that server's databases
through the Docker bridge (agent 0.4.5+). The engines accept connections from Docker's default address pools,
`172.16.0.0/12,192.168.0.0/16`; the firewall only lets them in on the Docker bridges. If the Docker daemon on your
servers uses other `default-address-pools`, set `KILN_DOCKER_NETWORKS` (comma-separated IPv4 CIDRs, /8–/30) in
`/opt/kiln/custom.env` and run `kiln-ctl up`; it applies to database users created or updated afterwards. Entries
that are not such ranges are ignored with a warning in the logs (Docker's defaults apply when none is left).

### Domains for new services

When a service is created (template, Git repository, Docker image) each public endpoint gets a domain:

- **Generate** — `<name>.<server-ip-with-dashes>.sslip.io` (e.g. `minio-files.63-182-218-247.sslip.io`). Works at
  once, with a Let's Encrypt certificate, no DNS setup. The default when no test domain is configured. It points at the
  leader server (or the site's load balancer); a service on several servers without a load balancer is reached on the
  leader only. sslip.io / nip.io names are shared by all their users (common certificate rate limits, no cookie
  isolation): fine for trying things out, use your own domain for production.
- **Test domain** — `<slug>.<KILN_TEST_DOMAIN>` when you run a wildcard test domain (the default then).
- **Custom domain** — Kiln shows the record(s) to add (`A` → the server's IPv4, `AAAA` → its IPv6; one per server for
  DNS round-robin, or the load balancer only) and checks DNS live until the name points at the server. Cloudflare
  proxying ("orange cloud") is detected: keep the record "DNS only" until the certificate is issued.

Organizations pick the generated-domain provider (sslip.io, nip.io, off) in **Settings → Domains**. Server-wide settings
in `.env` (then `kiln-ctl up`):

| Variable | Default | |
|---|---|---|
| `KILN_GENERATED_DOMAIN_SUFFIX` | `sslip.io` | `nip.io`, the domain of a self-hosted [sslip.io server](https://github.com/cunnie/sslip.io), or `off` |
| `KILN_DNS_RESOLVER` | `doh` | how the DNS check resolves: `doh` (DNS-over-HTTPS, no local cache) or `system` (the host's resolver) |
| `KILN_DNS_DOH_URL` | `https://cloudflare-dns.com/dns-query` | any DNS-over-HTTPS JSON endpoint (e.g. `https://dns.google/resolve`) |

## 4. Operate: `kiln-ctl`

```bash
kiln-ctl status                          # services, health, version, URLs, PHP thread usage, last backup
kiln-ctl logs [service] [-f]             # e.g. kiln-ctl logs control-plane -f
kiln-ctl doctor                          # DNS, certificates, ports, disk, containers, agent API (mTLS), PHP threads, backups
kiln-ctl admin reset-password you@example.com [--password=...]
kiln-ctl admin create ops@example.com [--token=cli]
kiln-ctl artisan <command>               # php artisan in the control-plane container
kiln-ctl prune-images [--dry-run]        # remove Kiln images except the current and previous version
kiln-ctl registry status                 # built-in image registry: address, size, answers with its credentials
kiln-ctl registry gc [--dry-run] [--force] # delete registry layers no image references (stops the registry briefly)
kiln-ctl up | down | restart [service]
```

## 5. Upgrade

```bash
kiln-ctl update                 # latest release
kiln-ctl update --version v1.3.0
```

An update:

1. takes a backup (`backups/kiln-backup-<ts>-pre-update-<old>.tar.gz`);
2. fetches the new deploy bundle and pulls the new images (if a pull fails, nothing changes);
3. recreates the stack. The `control-plane` service runs the migrations, and `horizon`, `reverb` and
   `scheduler` wait until it is healthy;
4. recreates every service whose **mounted config files** changed (see below) and prints their names;
5. health-checks every container and `https://<domain>/up`;
6. after a successful update, removes older Kiln images (see below).

**Old images.** Each release pulls new `kiln-control-plane`, `kiln-edge` and `kiln-builder` images (about 1 GB
together), so a host that updates often fills its disk. After a successful update kiln-ctl records the version it
came from as `KILN_PREVIOUS_VERSION` in `.env` and removes every other tag of those three images: the current and
the previous version stay, so a manual rollback (`kiln-ctl update --version <previous>`) needs no download.
Third-party images (Postgres, Valkey, Grafana, …), images still used by a container and volumes are never touched.
Run it on its own with `kiln-ctl prune-images` (`--dry-run` lists what it would remove); set
`KILN_PRUNE_IMAGES=0` in `.env` to keep every image. With `KILN_PULL=0` (images built locally, e.g.
`--build-from-source`) an update never prunes: removed images could not be pulled again. `kiln-ctl prune-images`
still works there and warns first.

**Registry storage.** Every docker build pushes an image to the built-in registry (`registry-data` volume). Two
steps keep it from growing forever:
- **Daily, in the control plane** (`kiln:registry-prune`, 03:45, after the artifacts prune): deletes the images of
  builds whose artifact was pruned (each site keeps its newest `KILN_ARTIFACTS_KEEP` builds, default 10). An image
  stays while a release may still run it (pending, live or kept for rollback), while its build is running or less
  than a day old, and tags that aren't build ids are never touched. Images of a deleted site's builds go
  `KILN_REGISTRY_DELETED_SITE_GRACE_DAYS` (default 7) days after the build. Preview with
  `kiln-ctl registry prune --dry-run`; run it now with `kiln-ctl registry prune`.
- **Weekly, from cron** (`/etc/cron.d/kiln-registry-gc`, Sunday 04:17, written by `kiln-ctl up`/`update`):
  `kiln-ctl registry gc` deletes the layers nothing references any more, which is what frees disk space. The
  registry is stopped while it runs (a push during garbage collection could lose layers), so it runs at night and is
  skipped (logged, tried again the next week) while an image build is queued or running, or when the control plane
  can't tell; `--force` runs it anyway. `KILN_REGISTRY_GC=0` removes the cron entry. Output goes to `/var/log/kiln-registry-gc.log`.

**Mounted config files.** Some services read config files bind-mounted from `/opt/kiln/observability/` and
`/opt/kiln/deploy/` (`loki.yaml`, `tempo.yaml`, the gateway `Caddyfile`, Grafana provisioning and dashboards).
An update replaces those directories, but a running container keeps the files it was started with (the mount
holds the old file), and `docker compose up` only recreates services whose compose definition changed. So
after `compose up`, `kiln-ctl` compares what each running container sees at its Kiln mounts with the files on
disk and force-recreates exactly the services that differ:

```
==> mounted config files changed: recreating loki
  ✓ recreated loki
```

The same check runs on `kiln-ctl up`, after a restore and during a rollback. Run it on its own with
`kiln-ctl reload-configs`, for example after editing `/opt/kiln/observability/loki/loki.yaml` by hand (such
edits are replaced by the next update). kiln-ctl v0.2.5 and older did not do this, so Loki could keep the previous
`loki.yaml` (access logs in **Network Logs** were then not queryable). An update is run by the kiln-ctl that is
already installed, so after updating *from* v0.2.5 or older run `kiln-ctl reload-configs` once; it fixes such
containers.

**Upgrading from 0.2.x with the thread hotfix.** If you added `FRANKENPHP_CONFIG=num_threads 24` to
`/opt/kiln/custom.env`, the update keeps working: a thread count in `FRANKENPHP_CONFIG` still wins over the
automatic sizing (the containers log a notice). It is no longer needed, because agents now long-poll their own
`agent-api` service (see [Performance](#performance-php-threads-and-worker-mode)). Remove the line, then run
`kiln-ctl up`. `kiln-ctl doctor` reports it until you do.

If step 3, 4 or 5 fails, `kiln-ctl` **rolls back automatically**. It restores the previous deploy files and
`KILN_VERSION`, restores the database, storage and Fleet CA from the pre-update backup (the new migrations
may already have run), and starts the previous version again.

### Upgrading the server agents

An update does not touch your servers: each keeps running its `kiln-agent` until you upgrade it. The new
control-plane image ships the matching agent build (`/install/agent/linux-{amd64,arm64}`), and after an update
`kiln-ctl update` prints how many agents are older. **Servers** shows each agent's version with *update
available*; organization admins update one server (**Update** next to the version in the list, or **Update agent**
on the server page, `POST /api/v1/servers/{server}/agent/upgrade`), the servers they tick in the list (**Update
selected**), or every outdated one (**Update all agents**).

The agent downloads the build from the panel, verifies its SHA-256, checks that it runs (`kiln-agent version`),
swaps it atomically (the previous binary stays as `/usr/local/bin/kiln-agent.prev`), restarts, and reports the new
version and binary checksum in its next heartbeat. Supervised programs restart with it (`KillMode=mixed`).
A bulk update upgrades `KILN_AGENT_UPGRADE_BATCH_SIZE` servers at a time (default 2) and stops at the first
failure; an upgrade fails when the agent has not come back with the new build within `KILN_AGENT_UPGRADE_TIMEOUT`
seconds (default 600). Failures raise the *Agent upgrade failed* alert. To roll a server back by hand:
`mv /usr/local/bin/kiln-agent.prev /usr/local/bin/kiln-agent && systemctl restart kiln-agent`.
`kiln-ctl artisan kiln:agents` shows the shipped build and the number of outdated agents.

Commands in flight during an agent restart are not lost: each agent process has a session id, and commands
delivered to the previous process are delivered again (Caddy routes, telemetry, processes, cron, firewall and other
`*.apply` state) or fail with "The agent restarted before running the command" (deploy steps, scripts). A command the
agent never acknowledges is handled the same way after `KILN_AGENT_COMMAND_LEASE` seconds (default 90). Agents
before this release get the new behaviour after their next upgrade; until then the lease covers them.

### Performance: PHP threads and worker mode

The web tier is two FrankenPHP services built from the same image, each with its own PHP thread pool:

| Service | Serves (edge routing, both hosts) | PHP mode | Threads |
|---|---|---|---|
| `control-plane` | the panel and the REST API: everything not listed below | **worker mode**: Laravel boots once per thread (Laravel Octane's FrankenPHP worker) | `KILN_PHP_WORKERS` workers (default 2 × CPUs), autoscaled up to `KILN_PHP_MAX_THREADS` (default max(8, 4 × CPUs)) |
| `agent-api` | `/agent/*` (agents), `/install/*` (installer), `/api/internal/*` (builders, artifacts) | classic (one boot per request) | 32 started, autoscaled up to `KILN_AGENT_API_THREADS` (default 128) |

The split matters because each agent and each builder holds a 30-second long-poll open all the time (it
returns as soon as a command or build is queued). A waiting long-poll occupies one PHP thread. Up to
v0.2.x, the panel and the agents shared FrankenPHP's default pool of 2 × CPUs threads. On a 2-vCPU host that
is 4 threads, so three servers plus the builder took all of them, and every page queued for 5–11 s behind
the long-polls. Now the panel's threads serve only people. A waiting long-poll costs about 2–3 MB and no
CPU, and it does not hold a database connection (agents wait on Valkey).

Sizing (in `/opt/kiln/.env`, then `kiln-ctl up`):

- `KILN_AGENT_API_THREADS`: at least *managed servers + builders + 8*. The default of 128 covers about
  120 servers within the 512 MB limit of `agent-api`. For bigger fleets, raise it together with that
  limit (about 3 MB per thread).
- `KILN_PHP_WORKERS` / `KILN_PHP_MAX_THREADS`: the defaults suit 2–8 CPUs. Each panel worker keeps a
  booted app, about 12 MB.
- `KILN_WORKER_MODE=0`: fallback to classic mode for the panel. It is about 2–4× slower per request but
  keeps no state between requests. `KILN_PHP_THREADS` then sets the starting thread count.
- Each container logs its pool at start, e.g. `kiln: web: PHP worker mode, 4 workers, num_threads 6
  max_threads 8`. `kiln-ctl status` shows live usage (`PHP threads: panel 1/8 busy · agent-api 5/128
  busy`), and `kiln-ctl doctor` flags a pool that is ≥ 80 % busy or saturated.

Measured on the production image with 2 pinned CPUs, Postgres and Valkey. Panel TTFB is the median of 20
requests. Before = v0.2.0 (one shared pool of 4 threads).

| | before, idle | before, 4 long-polls | before, 10 long-polls | after, idle | after, 50 long-polls |
|---|---|---|---|---|---|
| `/up` | 5.4 ms | 7.6 s | timeout (> 15 s) | 1.8 ms | 2.7 ms |
| `/login` | 11 ms | 11.1 s | timeout | 3.9 ms | 3.5 ms |
| `/servers` | 32 ms | 11.1 s | timeout | 16.6 ms | 16.2 ms |
| project canvas | 38 ms | 11.0 s | timeout | 23 ms | 23 ms |

Throughput with 16 concurrent clients: `/login` 212 → 978 req/s and the canvas 66 → 119 req/s (classic
→ worker). The image also ships `config:cache`, `route:cache`, `event:cache` and `view:cache` at start, an
authoritative Composer classmap, and OPcache without timestamp checks and with a 4 MB realpath cache. The
CLI (Horizon, Reverb, scheduler, healthchecks) gets an OPcache file cache, which cuts an artisan boot from
86 to 40 ms with no extra RAM. JIT stays off: tracing JIT measured within noise (+2–7 %) for +15 MB.

## 6. Backup and restore

```bash
kiln-ctl backup                          # -> /opt/kiln/backups/kiln-backup-<UTC timestamp>.tar.gz
kiln-ctl restore /opt/kiln/backups/kiln-backup-20260101T030000Z.tar.gz --yes
```

A backup contains:

- `db.dump`: `pg_dump -Fc` of the database;
- the `kiln-ca` volume (Fleet CA certificate and agent API certificate), `app-storage` (build artifacts,
  app files) and `caddy-data` (ACME account and certificates);
- `.env` and `custom.env`.

> **The Fleet CA is critical.** Every agent trusts only this CA, and its private key is stored in the database
> encrypted with `APP_KEY`. A backup is only useful with its **database and `.env` together**. If you lose
> either one, every server must be re-enrolled. Keep copies **off the host**.

- Retention: the newest `KILN_BACKUP_KEEP` backups are kept (default 14).
- Schedule a daily backup with cron:
  `echo '15 3 * * * root /usr/local/bin/kiln-ctl backup --quiet' > /etc/cron.d/kiln-backup`
- Encryption: set `KILN_BACKUP_PASSPHRASE` in `.env` to write `*.tar.gz.enc` (AES-256, `openssl enc -pbkdf2`).
  `restore` needs the same passphrase.
- Off-site copies (S3-compatible, via `curl --aws-sigv4`): set `KILN_BACKUP_S3_ENDPOINT`
  (e.g. `https://s3.eu-central-1.amazonaws.com`), `KILN_BACKUP_S3_BUCKET`, `KILN_BACKUP_S3_REGION`,
  `KILN_BACKUP_S3_ACCESS_KEY`, `KILN_BACKUP_S3_SECRET_KEY`, and optionally `KILN_BACKUP_S3_PREFIX`. Uploads
  use path-style URLs. The local copy is kept even when an upload fails.

**Move to a new host:** install Kiln on the new host with the same `--domain`, copy the backup over, run
`kiln-ctl restore <file> --yes`, then point DNS at the new host. The restore brings back the old `.env`
(including `APP_KEY`), so agents keep working without re-enrolling.

## 7. Change the domain

```bash
kiln-ctl domain set kiln.new-example.com [--keep-old]
```

This command:

- takes a backup;
- rewrites the URLs in `.env`;
- re-issues the agent API certificate for `agents.<new>`, keeping the **old agents host** as an alias,
  because enrolled agents keep calling the API host they enrolled with. Keep that old DNS record pointing
  here;
- moves the built-in registry to `registry.<new>`, keeping the **old registry host** as an alias: images of
  earlier releases are pinned to the old name, so keep its DNS record too (a rollback to a release built before
  the move may need a rebuild, because servers get credentials for the current registry name only);
- with `--keep-old`, redirects the old panel domain to the new one.

Create the new DNS records first. Users of the `kiln` CLI need to run `kiln login --url https://<new>` again.

## 8. Uninstall

```bash
kiln-ctl backup                                   # optional, then copy it off the host
cd /opt/kiln && docker compose -p kiln --env-file .env -f deploy/compose.yml --profile observability down -v
rm -rf /opt/kiln /usr/local/bin/kiln-ctl /etc/cron.d/kiln-backup
```

`down -v` deletes every volume: the database, the Fleet CA and certificates. Leave out `-v` to keep the
data. Managed servers keep running. Remove the agent there with `systemctl disable --now kiln-agent`.

## 9. Troubleshooting

| Symptom | Check / fix |
|---|---|
| Installer: `DNS does not point at this host` | Create the printed A/AAAA records and wait for propagation (`dig +short kiln.example.com`). With Cloudflare, use DNS only. |
| Installer: `port 80 is in use` | Stop the other web server, e.g. `systemctl disable --now nginx apache2 caddy`. |
| Browser shows a certificate error | `kiln-ctl logs edge` and look for ACME errors. Ports 80/443 must be reachable from the internet (cloud firewall / security group). Let's Encrypt rate limits apply to repeated reinstalls. |
| Stack not healthy | `kiln-ctl status`, `kiln-ctl logs control-plane`. A migration error shows in the `control-plane` logs. |
| Server install command fails to enroll | The server must reach `https://<domain>` (system CAs) **and** `https://agents.<domain>` (Fleet CA). Run `kiln-ctl doctor`. The agent API must answer `401` without a client certificate. |
| New server stays **Waiting for agent** after you deleted the old one and reinstalled on the same machine | The agent enrolls only when `/etc/kiln` has no identity, so it keeps the deleted server's certificate, ignores the new token and gets `401`. On the machine: `sudo systemctl stop kiln-agent && sudo mkdir -p /root/kiln-old && sudo mv /etc/kiln/agent.key /etc/kiln/agent.crt /etc/kiln/ca.crt /etc/kiln/agent.json /root/kiln-old/`, then run a freshly generated install command. |
| Agents go offline after a restore | The restored `.env` / `APP_KEY` must belong to the same backup as the database. The `kiln-ca` volume is re-synced by the edge within 3 seconds. |
| Live updates in the UI don't refresh | Check that the `reverb` service is healthy. Browsers connect to `wss://<domain>/app/…` through the edge. |
| `KILN_EDGE_SUBNET ... overlaps` | Pick another private /24 in `.env` and re-run the installer. The app trusts proxy headers only from that subnet. |
| Builds stay queued | `kiln-ctl logs builder`. The builder polls `https://<domain>` with `KILN_BUILDER_TOKEN`. Docker-mode builds need a `builder` server: the bundled builder does native builds only (`KILN_LOCAL_BUILDER_MODES=native`, the default). |
| Docker build fails at push (`lookup registry.kiln.local … no such host`, `401`, `x509`) | The install has no built-in registry yet or its DNS is missing: run `kiln-ctl up` (adds `KILN_REGISTRY_*`), create the `registry.<domain>` record, then `kiln-ctl registry status`. `x509` with `--tls internal`: see section 2. |
| Panel slow (seconds per page) | `kiln-ctl doctor`, section *PHP threads*. A saturated `agent-api` pool delays agents, not the panel. Raise `KILN_AGENT_API_THREADS` (or `KILN_PHP_MAX_THREADS` for the panel) in `.env`, then `kiln-ctl up`. See [Performance](#performance-php-threads-and-worker-mode). |
| Something only breaks in worker mode | Set `KILN_WORKER_MODE=0` in `.env`, run `kiln-ctl up` and report it. The panel then boots Laravel for every request (classic mode). |
| Low memory | Lower `KILN_HORIZON_MAX_PROCESSES` in `.env`, or move observability to its own host. |

## 10. Testing the installer locally (`--tls internal`)

`--tls internal` makes Caddy issue certificates from its own local CA and skips the DNS check. It also
allows custom ports, for example `--http-port 8080 --https-port 9443 --domain kiln.test`. Reach the panel with
`curl -k --resolve kiln.test:9443:127.0.0.1 https://kiln.test:9443/up`. Do not use internal TLS in
production: server install scripts and agents would not trust the panel certificate.

Building images yourself (the release workflow does the same, multi-arch):

```bash
docker build -f control-plane/Dockerfile --build-arg KILN_VERSION=dev -t kiln-local/kiln-control-plane:dev .
docker build -f deploy/builder.Dockerfile --build-arg KILN_VERSION=dev -t kiln-local/kiln-builder:dev .
docker build --build-arg KILN_VERSION=dev -t kiln-local/kiln-edge:dev deploy/edge
```

## 11. Releasing (maintainers)

Push a tag `vX.Y.Z` (`vX.Y.Z-rc.N` for pre-releases, which are not tagged `latest`). The
`.github/workflows/release.yml` workflow then:

- builds `kiln-control-plane`, `kiln-builder` and `kiln-edge` on native amd64 and arm64 runners and pushes
  multi-arch manifests to `ghcr.io/<owner>/…:<tag>` and `:latest`;
- builds `kiln-agent`, `kiln` and `kiln-builder` with `make build`;
- creates the GitHub release with the binaries, `kiln-deploy.tar.gz`, `install.sh` (pinned to the tag),
  `kiln-ctl` and `SHA256SUMS`.

It uses only `GITHUB_TOKEN`. After the first release, make the three GHCR packages **public** so that hosts can
pull them anonymously.
