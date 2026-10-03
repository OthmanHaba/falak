# Machine check before provisioning (plan, v0.6.0)

Users bring their own servers, and those servers already run software. Provisioning (`provision.apply`) installs
a fixed list of Ubuntu packages and fails or misbehaves when other software is present. v0.6.0 checks the machine
first, decides per component what to do, shows the result, and then applies only what is safe.

## The incident

A user's Ubuntu 26.04 VM had Docker from Docker's repository (`docker-ce`, `docker-buildx-plugin`,
`docker-compose-plugin`). `servers.docker.packages` is `docker.io, docker-compose-v2, docker-buildx`, and the apt
step diffs package *names*: `Apt.Missing` asks `dpkg-query -W` for exactly those three names. None of them is
installed (the engine is `docker-ce`), so all three are "missing" and go to one `apt-get install`. `docker.io`
conflicts with `docker-ce` (apt plans to remove it), and Ubuntu's `docker-buildx` ships
`/usr/libexec/docker/cli-plugins/docker-buildx` without declaring a conflict with `docker-buildx-plugin`, so dpkg
fails with "trying to overwrite …, which is also in package docker-buildx-plugin". The apt step has no idea that
two package *families* provide the same thing. The machine check moves that knowledge into the control plane,
where it can be tested, shown and explained, and keeps the agent's apt step dumb.

## One system: Detect → Decide → Report → Apply

1. **Detect** (agent, read-only): `provision.inspect` returns a structured report. It changes nothing.
2. **Decide** (control plane, pure): `Servers\Domain\MachineCheck\DecisionEngine` reads the report and the server's
   wanted stack and gives every component one decision.
3. **Report**: the latest inspection and its decisions are stored per server (`servers_machine_inspections`), shown
   in the *Machine check* panel on the server page and served by the API.
4. **Apply**: `ProvisioningPlanBuilder` builds `provision.apply` from the decisions. Adopted and blocked components
   are not installed; the agent verifies adopted ones and never installs them.

### Decisions and severities

| Decision | Meaning | UI label |
|---|---|---|
| `install` | Nothing there; Kiln installs / configures it as before. | Install |
| `adopt` | A compatible, working one is there; Kiln uses it and does not install the package. | Use existing |
| `complete` | Partly there; Kiln installs only the missing pieces, **from the same source**. | Install missing parts |
| `block` | A conflict Kiln won't resolve automatically; reason plus a fix hint. Nothing is applied. | Blocked |
| `skip` | Not part of this server's stack; only reported because something was found. | Not managed |

Every row and every note carries a severity: `info`, `warning` or `block`. A row's severity is the highest of its
notes. Rows sort blocks first, then warnings, then the rest in component order.

## Rules per component

Minimum versions, package families, ports and the container process names live in `config/servers.php`
(`servers.machine_check`).

| Component | Install | Adopt | Complete | Block | Notes (info / warning) |
|---|---|---|---|---|---|
| Base packages | some missing (apt installs those) | all present | — | — | — |
| Docker (engine, compose, buildx) | nothing found → `docker.io docker-compose-v2 docker-buildx` | engine + compose + buildx work | missing compose / buildx from the engine's family: Docker repo → `docker-compose-plugin` / `docker-buildx-plugin`; Ubuntu `docker.io` → `docker-compose-v2` / `docker-buildx` | engine below `minimum_versions.docker`; snap Docker; rootless-only Docker; `podman-docker`; Docker-repo engine with pieces missing but the Docker apt repo not configured; a manual (non-package) engine with pieces missing | warning: `daemon.json` `iptables: false` (published ports and Kiln's container firewall need iptables), `userns-remap` (release file ownership); info: `bip` / `default-address-pools`, Docker installed but not wanted (`skip`) |
| Database (PostgreSQL, MySQL, MariaDB) | wanted engine absent | wanted engine present (any source: Ubuntu, PGDG `postgresql-NN`, Oracle `mysql-community-server`, MariaDB repo) at or above the minimum → keep its packages, cluster and major version, only enable/start the service | — | version below minimum; another engine of the same kind (MySQL ↔ MariaDB, Percona); the engine's port held by a container (`docker-proxy` / a published container port) or by another process | info: engines found that are not wanted (`skip`, e.g. MySQL on a PostgreSQL server) |
| Cache (Redis, Valkey) | wanted engine absent | wanted engine present (Ubuntu or vendor repo) at or above the minimum | — | below minimum; the other engine (both own 6379); 6379 held by a container or another process | info: unwanted engines |
| Edge (Caddy / kiln-edge, ports 80, 443, 2019) | servers that serve HTTP, ports free | `kiln-edge.service` already there (re-provision); a Caddy package (any source) without an active `caddy.service` is reused | — | 80 / 443 / 2019 held by a non-Kiln process (nginx, apache2, a container's `docker-proxy`, …); an active non-Kiln `caddy.service` (Kiln would disable it) | warning: nginx / apache2 installed and enabled but not listening (they would take port 80 on the next boot) |
| PHP and FrankenPHP | wanted versions absent | — | some wanted versions / extensions present (installed from their current source; the runtime keeps the v0.5.2 rules: `ppa:ondrej/php` when it builds the release, otherwise the archive) | — | warning: a non-package `php` / `frankenphp` in `/usr/local/bin` that Kiln's would replace or shadow; info: other PHP versions |
| Node | Kiln's `/opt/kiln/node/<version>` (always) | Kiln's wanted version already there | — | — | warning: a non-Kiln `/usr/local/bin/node` (Kiln's symlink replaces it); info: nvm, NodeSource, Ubuntu, snap installs (left alone) |
| SSH | Kiln's drop-in `50-kiln.conf` (port, root login, no passwords) | — | — | password login would be disabled while no login user (root, UID ≥ 1000 with a shell) has an `authorized_keys` entry | warning: an earlier `sshd_config.d` file sets `PasswordAuthentication` / `PermitRootLogin` / `Port` and wins over Kiln's (sshd keeps the first value); sshd listens on a port other than the server's SSH port (Kiln moves it) |
| Firewall | Kiln's `table inet kiln` (applied by the Network module after provisioning) | — | — | — | **warning** when ufw or firewalld is active (see below); info: other nftables tables |
| Swap | no active swap and the RAM rule wants one → `/swapfile` | any active swap device or file → keep it, no `/swapfile` | — | — | info: none needed (≥ 8 GB RAM) |
| Hostname | provider servers (named by Kiln at creation) | custom servers keep the machine's hostname | — | — | — |
| Unattended upgrades | absent, or Kiln's own config → Kiln's `20auto-upgrades` + `52kiln-unattended` | an existing config that is not Kiln's → not overwritten | — | — | warning: the existing config disables automatic upgrades |
| fail2ban | absent → install + enable | installed → keep its jails (Kiln writes none) and enable the service | — | — | info: number of custom jail files |

### Why ufw / firewalld is a warning, not a block

`agent/internal/netcfg/firewall.go` owns only `table inet kiln` and never touches other tables. With ufw (iptables-nft)
or firewalld active, a packet is checked by every base chain on the input hook, so a port must be allowed by both
firewalls. Nothing Kiln does can lock the machine out because of ufw (Kiln never enables or changes it; SSH stays as
reachable as it was), and provisioning itself does not depend on inbound ports (the agent connects out). The
failure mode is "a site is not reachable", which a warning with the fix (`ufw allow 80,443/tcp` or `ufw disable`)
explains. Blocking would stop every machine that merely has ufw enabled with sensible rules.

## Flow and state

```
enroll / Re-provision ──► agent has provision.v2? ──no──► provision.apply (as today)
                               │yes
                               ▼
                  status provisioning ("Checking the machine…")
                  provision.inspect ──failed──► status error "Machine check failed: …"
                               │finished
                               ▼
        store report + decisions (servers_machine_inspections)
                               │
               blocks? ──yes──► status needs_attention ("Needs attention"),
                               │                message = summary of the blocks, nothing applied
                               │no
                               ▼
                 provision.apply built from the decisions (as today otherwise)
```

- **Re-check** (`POST /servers/{server}/inspection`, API the same path) re-runs the inspection. It never applies.
  On a `needs_attention` server the status message follows the new result ("Nothing blocks provisioning any
  more. Provision to continue." once clear).
- **Provision** (`POST /servers/{server}/provision`) applies the plan when the latest inspection has no blocks
  (`needs_attention` → `provisioning`). It is disabled in the UI and refused (422) while something blocks.
- **Re-provision** (menu) and **Retry provisioning** run the whole flow again (inspect, then apply).
- Later converges (PHP versions, a database engine added later, the timezone) rebuild the plan from the stored
  report, so an adopted Docker is never replaced by Ubuntu's packages. Adding a database engine is refused when
  the stored report blocks that engine.
- `needs_attention` is not `active`: deploys, terminals, private networks and the firewall skip the server exactly
  like a provisioning one. Alerting gets a `servers.needs_attention` warning.
- One row per server in `servers_machine_inspections`: `command_id`, `purpose` (`provision` / `check`), `status`
  (`running` / `finished` / `failed`), `report` (JSON), `decisions` (JSON), `blocking`, `agent_version`, `error`,
  `checked_at`, `created_at`. A running re-check keeps the previous report visible.

## Protocol changes and compatibility

- New feature flag **`provision.v2`** (agent `version.Features`). It means: the agent runs `provision.inspect`
  and understands `provision.apply` `components`.
- New command **`provision.inspect`** (`commands/provision.inspect.schema.json`): empty payload, read-only,
  redeliverable. `$defs.result` is the report (packages with origin, snaps, apt sources, services, TCP listeners
  with process / unit / container, containers' published ports, Docker, SSH, firewall, swap, Node, PHP,
  FrankenPHP, unattended-upgrades, fail2ban, hostname, detector errors).
- **`provision.apply`** gets an optional `components` list: `{name, decision, packages?, service?}`. The agent:
  - never installs packages listed by an `adopt` component (they are removed from the apt step) and adds an
    `adopt:<name>` step that only verifies they are still installed;
  - skips the swap and hostname steps for adopted swap / hostname;
  - for adopted unattended-upgrades only verifies the package and writes no config.
- Agents without `provision.v2`: the control plane never sends them `provision.inspect` (they would not know it),
  `PayloadCompatibility` strips `components`, and their plan is today's plan (no report → no decisions).

## Open questions (decided safely for now)

1. **Explicit hostname.** Kiln has no "hostname" setting separate from the server name. Custom servers keep the
   machine's hostname; provider servers are named as before. A future setting could opt in to renaming.
2. **ufw / firewalld**: warning (above). If users prefer, this can become a block with an "I understand" override.
3. **SSH port mismatch**: warning, Kiln keeps moving sshd to the server's SSH port (the Network firewall opens
   `network.ssh_port`). Adopting the machine's port needs a per-server port in the firewall first.
4. **Provision after Re-check** uses the stored report (the user just looked at it) rather than inspecting again.
5. **Percona Server** and other MySQL forks are treated as "another engine" (block) rather than adopted.
6. **Rootless Docker next to a system daemon** only warns; rootless-only blocks.
7. **Later converges on an active server** (PHP versions, timezone) use the stored report, which predates Kiln's own
   installs; that reproduces what the first plan did. A block found by a re-check on an active server is shown, but
   does not change the server's status or stop converges (only a new database engine is refused).
8. **The inspect result is not schema-validated before use**: Fleet only logs result/schema mismatches, and the
   decision engine reads every section defensively (a missing section counts as "nothing found").
