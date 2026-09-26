# Kiln local end-to-end simulation

This is a laptop-sized version of a real Kiln install: the control plane (FrankenPHP +
Horizon + Reverb + Postgres 17 + Valkey), an edge that terminates TLS and checks agent
mTLS, the full observability stack, and three managed Ubuntu 24.04 servers. Each server
runs systemd as PID 1 and sshd.

```
host ──https://localhost:8443──► edge (Caddy)
                                  ├─ kiln.test / localhost   TLS: Caddy internal CA ─► control-plane :8080 (FrankenPHP)
                                  │                                                └► reverb :8080 (/app, /apps)
                                  └─ agents.kiln.test        TLS: Fleet-issued cert + agent mTLS ─► control-plane
srv-app-1 · srv-app-2 · srv-db-1  ── fleet network (10.77.20.0/24) ──► edge as kiln.test / agents.kiln.test
  (Ubuntu 24.04, systemd, sshd)   └─ observability network ─────────► gateway:4318 (OTLP)
control-plane · horizon · reverb  ── backend network (10.77.10.0/24) ► postgres · valkey
```

## Usage

```bash
cd sim
make up                  # build + start everything, waits until healthy (METRICS=mimir to switch backend)
make e2e                 # end-to-end checks (see below)
make enroll-all          # dev shortcut: mint a token per server and enroll all three
make enroll TOKEN=<t> [SERVERS="srv-app-1"]   # enroll with a token minted by the control plane
make token               # mint one install token via Fleet's Enrollment contract
make ca                  # export the edge TLS root to .data/edge-root.crt
make logs S=edge | make shell S=srv-db-1 | make ps
make down                # stop (keeps volumes) | make reset (also wipes volumes and .data/)
```

Endpoints on the host:

| What | URL |
|---|---|
| Control plane | `https://localhost:8443` (trust `.data/edge-root.crt`, or `curl -k`) |
| Agent API | `https://agents.kiln.test:8443` (`--resolve agents.kiln.test:8443:127.0.0.1`, trust the Fleet CA) |
| Grafana | `http://localhost:13000` (admin / admin) |
| OTLP ingress | `http://localhost:14318` |

Host ports are set in `sim.env` and are deliberately not the defaults, so the sim does not
clash with a standalone observability stack. To run the servers with amd64 agent binaries,
use `SIM_AGENT_ARCH=amd64 make up`. Emulation is required on arm64 hosts.

## Components

| Service | Built from / image |
|---|---|
| control-plane / horizon / reverb | `control-plane.Dockerfile` (context `../control-plane`): `dunglas/frankenphp:1.12.7-php8.4-trixie` + pdo_pgsql, redis, pcntl, intl, zip, bcmath, gmp, sockets. Composer deps come from `composer:2.10.3`, the frontend is built with `oven/bun:1.4.2`. Entrypoint roles `web` (runs migrations, then FrankenPHP), `horizon`, `reverb`. |
| postgres | `postgres:17.11` |
| valkey | `valkey/valkey:9.1.2-alpine` |
| edge | `edge/Dockerfile` (`caddy:2.11.4-alpine` + openssl, curl) |
| srv-app-1, srv-app-2, srv-db-1 | `server.Dockerfile` (`ubuntu:noble-20260911` + systemd, openssh-server), privileged, private cgroupns |
| gateway, loki, tempo, victoriametrics or mimir, grafana | included from `../observability/compose.yml` |

The servers are privileged with their own cgroup namespace so that systemd can run as PID 1.
Units that don't work in a container (udev, getty, timesyncd, …) are masked, and sshd uses
Ubuntu's stock `ssh.socket` activation. Each container generates its own SSH host keys at
first boot. `SIM_SSH_PUBKEY` in `sim.env` authorises a key for `root`.

The agent binary is mounted, not baked in. The repository is mounted read-only at
`/opt/kiln/src`, and at boot `kiln-sim-agent-link.service` links
`agent/bin/kiln-agent-linux-$SIM_AGENT_ARCH` to `/usr/local/bin/kiln-agent` if the file
exists. If the agent hasn't been built, the servers still boot. The control plane serves the
same binaries to the installer (`KILN_AGENT_BINARIES_PATH=/opt/kiln/src/agent/bin`).

## Integration points

1. **Fleet CA → edge client verification.** The control plane runs with
   `KILN_CA_PATH=/kiln/ca` on the shared `kiln-ca` volume, and Fleet writes the CA
   certificate (never the key) to `/kiln/ca/ca.pem`. The edge mounts the volume read-only,
   checks the file every 3 s, copies it to `/etc/caddy/trust/ca.pem` and hot-reloads Caddy.
   Until the file exists, the edge trusts a throwaway placeholder CA whose key has been
   discarded, so every non-enroll agent call fails closed.
2. **Agent API server certificate.** Agents pin the Kiln CA on the mTLS API, so that API has
   its own host, `agents.kiln.test` (`KILN_AGENT_API_URL=https://agents.kiln.test/agent/v1`).
   The panel stays on `kiln.test`, which uses a browser/installer-trusted cert. The
   control-plane entrypoint runs `php artisan fleet:ca:server-cert agents.kiln.test --out /kiln/ca`
   when `agent-api.{pem,key}` are missing, expiring or not signed by the current CA. The
   edge adds the `agents.kiln.test` site as soon as they appear.
3. **Agent mTLS contract** (`contracts/agent-protocol`). `client_auth verify_if_given` runs
   against the Fleet CA. `/agent/v1/*` except `/agent/v1/enroll` without a client cert gets
   `401` at the edge. A cert from any other CA fails the TLS handshake. With a valid cert the
   edge sets `X-Kiln-Client-Cert-Fingerprint` to the lowercase-hex SHA-256 of the client DER
   cert. The edge always strips any client-supplied value of that header. The control plane
   trusts the header only from `KILN_AGENT_TRUSTED_PROXIES=10.77.10.0/24` (the backend
   network). `GET /_edge/whoami` shows what the edge would forward, which helps when
   debugging.
4. **Edge TLS root → servers.** The edge publishes Caddy's internal root to the `edge-pki`
   volume. At boot, each server installs it into the system trust store
   (`kiln-sim-trust.service`), so `curl https://kiln.test/install/<token> | sh` works
   unchanged.
5. **Telemetry.** Servers reach the OTLP gateway at `http://gateway:4318`
   (`KILN_OTLP_ENDPOINT`), and the control plane reaches Grafana, Loki, Tempo and the metrics
   API by service name (`KILN_GRAFANA_URL`, …).

## e2e (`make e2e` → `e2e.sh`)

| # | Check | Status |
|---|---|---|
| 1 | every container running, and healthy where it has a healthcheck | works |
| 2 | `GET /up` through the edge from the host (edge CA verified) and from every server over the fleet network | works |
| 3 | edge mTLS: no-cert → 401, spoofed header → 401, enroll passes, fingerprint = sha256(DER) (checked against a disposable edge with a throwaway CA), untrusted CA → handshake failure, Fleet CA trusted, agent API cert chains to the Fleet CA | works |
| 4 | `../observability/smoke-test.sh` against the sim stack, and OTLP gateway reachable from every server | works |
| 5 | each server: PID 1 = systemd, `systemctl is-system-running` = `running`, sshd answers, agent binary mounted, private network reachability | works |
| 6 | per server: agent enrolled and active, heartbeat and long-poll over mTLS without errors, host metrics in the metrics backend | works after `make enroll-all`, otherwise PENDING |
| 7 | site provisioning and deploy, app APM data on the Laravel/Node/Queues dashboards, agent deployment log events → *Deployment failed* alert | **PENDING**: needs the Sites/Deployments modules, the agent `deploy.*` executors and the APM packages running in a deployed site |

`make token` and `make enroll-all` are dev shortcuts. They call
`Kiln\Fleet\Contracts\Enrollment::issueInstallToken()` through `artisan tinker` with a fixed
sim organisation id (`SIM_ORG_ID`). Once the Servers module has a real "add server" flow,
use a token from that flow with `make enroll TOKEN=…`.
