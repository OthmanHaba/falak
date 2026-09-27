# Installing Kiln (production)

Kiln runs as a Docker Compose stack on a single Linux host. The installer sets up Docker, writes a
generated configuration to `/opt/kiln/.env`, starts the stack, and creates the first administrator.
Day-2 operations use `kiln-ctl`.

```
internet ──:80/:443──► edge (Caddy)
                        ├─ kiln.example.com         Let's Encrypt ─► control-plane (FrankenPHP) · reverb (websockets)
                        ├─ agents.kiln.example.com  Fleet-CA cert + client-cert (mTLS) check ─► control-plane
                        └─ grafana.kiln.example.com Let's Encrypt ─► grafana            (optional)
control-plane · horizon · reverb · scheduler ─► postgres 17 · valkey
builder (kiln-builder serve: PHP/Composer, Node, Bun) ─► edge
```

`OWNER/kiln` in this document is a placeholder for the GitHub repository that publishes Kiln releases.

## 1. Requirements

| | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 / 24.04, Debian 12 (amd64 or arm64) | Ubuntu 24.04 |
| RAM | 2 GB (installer warns below 4 GB) | 4 GB; **8 GB with `--observability`** |
| Disk | 10 GB free | 25 GB+ (images, build artifacts, backups) |
| Network | public IPv4 (or IPv6); ports **80** and **443** free and reachable | |
| DNS | records for the panel and `agents.` host (below) | |

This host runs only Kiln. The servers Kiln manages are separate machines.

**Memory budget** (limits are caps, not reservations; steady state measured in the sim):

| Service | Limit | Typical |
|---|---|---|
| control-plane (web) | 512 MB | 120 MB |
| horizon (4 workers, `KILN_HORIZON_MAX_PROCESSES`) | 512 MB | 150–250 MB |
| reverb | 192 MB | 50 MB |
| scheduler | 256 MB | 60 MB |
| postgres (`shared_buffers=128MB`) | 512 MB | 50–150 MB |
| valkey (`maxmemory 128mb`, AOF) | 192 MB | 15 MB |
| edge | 128 MB | 35 MB |
| builder | 1.5 GB | idle 10 MB, builds up to ~1 GB |
| **core total** | **≈ 3.8 GB** | **≈ 0.8 GB idle** |
| observability: gateway, loki, tempo, victoriametrics, grafana | 64 + 384 + 384 + 384 + 512 MB | ≈ 0.7 GB |

## 2. DNS

Create these records before you install (replace the IP with your server's public address):

| Name | Type | Value | Why |
|---|---|---|---|
| `kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | panel, installer script, agent enrollment |
| `agents.kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | agent API (mTLS) |
| `grafana.kiln.example.com` | A (and/or AAAA) | `203.0.113.10` | only with `--observability` |

- The panel and Grafana get **Let's Encrypt** certificates automatically (HTTP-01/TLS-ALPN on ports 80/443).
- The **agents** host does *not* use Let's Encrypt. Agents pin Kiln's own Fleet CA, so the edge serves
  that host with a certificate issued by the Fleet CA, and verifies agent client certificates against it.
  Behind Cloudflare, set all records to **DNS only** (grey cloud): a proxy would terminate TLS and break mTLS.
- The installer checks that every name resolves to this host's public IP, and it prints the missing records.

## 3. Install

```bash
curl -fsSL https://raw.githubusercontent.com/OWNER/kiln/main/deploy/install.sh \
  | sudo bash -s -- --domain kiln.example.com --email you@example.com
```

Or pin a release with its own copy of the script:
`curl -fsSL https://github.com/OWNER/kiln/releases/download/v1.2.3/install.sh | sudo bash -s -- --domain ... --email ...`

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
| `--repo OWNER/NAME` | `KILN_REPO` | `OWNER/kiln` |
| `--image-prefix PREFIX` | `KILN_IMAGE_PREFIX` | `ghcr.io/<owner>` |
| `--build-from-source [--ref REF]` | `KILN_BUILD_FROM_SOURCE=1` | clones the repo and builds images locally (no registry) |
| `--source-dir PATH` | `KILN_DEPLOY_SOURCE` | use deploy files from a local checkout |
| `--http-port/--https-port` | `KILN_HTTP_PORT/KILN_HTTPS_PORT` | 80/443 (other ports only with `--tls internal`) |
| `--skip-dns-check`, `--force` | | |

Running the installer again is safe. Secrets are kept, settings from the flags are updated, the stack is
converged, and the admin is not created a second time. To enable observability later, re-run it with
`--observability`. The installer then creates a Grafana service account token for Kiln
(`KILN_GRAFANA_TOKEN`). Grafana's `admin` password is `GRAFANA_ADMIN_PASSWORD` in `/opt/kiln/.env`.

Next steps: log in, open **Servers → Create**, and run the printed install command on each server.
The agent binaries come from the control-plane image, so servers download them from your panel
(`/install/agent/linux-{amd64,arm64}`), not from GitHub.

### Files

```
/opt/kiln/.env            settings + secrets (install.sh; never commit or share)
/opt/kiln/custom.env      optional extra Laravel env (MAIL_*, KILN_* tuning) — loaded by the app containers
/opt/kiln/deploy/         compose.yml, kiln-ctl, image support files (replaced on update; previous kept as deploy.prev)
/opt/kiln/observability/  Loki/Tempo/Grafana/gateway configs
/opt/kiln/backups/        kiln-ctl backup output
```

For e-mail, set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and
`MAIL_FROM_ADDRESS` in `/opt/kiln/.env`, then run `kiln-ctl up`.

## 4. Operate: `kiln-ctl`

```bash
kiln-ctl status                          # services, health, version, URLs, last backup
kiln-ctl logs [service] [-f]             # e.g. kiln-ctl logs control-plane -f
kiln-ctl doctor                          # DNS, certificates, ports, disk, containers, agent API (mTLS), backups
kiln-ctl admin reset-password you@example.com [--password=...]
kiln-ctl admin create ops@example.com [--token=cli]
kiln-ctl artisan <command>               # php artisan in the control-plane container
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
4. health-checks every container and `https://<domain>/up`.

If step 3 or 4 fails, `kiln-ctl` **rolls back automatically**. It restores the previous deploy files and
`KILN_VERSION`, restores the database, storage and Fleet CA from the pre-update backup (the new migrations
may already have run), and starts the previous version again.

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
| Agents go offline after a restore | The restored `.env` / `APP_KEY` must belong to the same backup as the database. The `kiln-ca` volume is re-synced by the edge within 3 seconds. |
| Live updates in the UI don't refresh | Check that the `reverb` service is healthy. Browsers connect to `wss://<domain>/app/…` through the edge. |
| `KILN_EDGE_SUBNET ... overlaps` | Pick another private /24 in `.env` and re-run the installer. The app trusts proxy headers only from that subnet. |
| Builds stay queued | `kiln-ctl logs builder`. The builder polls `https://<domain>` with `KILN_BUILDER_TOKEN`. Docker-mode builds need a `builder` server: the bundled builder does native builds only (`KILN_LOCAL_BUILDER_MODES=native`, the default). |
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
