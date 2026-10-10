# Disaster recovery

Two things can be lost: the **control plane host** (the panel, its database, the Fleet CA, the keys) and an
**application server** (the sites, databases and volumes on it). This runbook covers both, what a backup holds,
the emergency kit, and the drills that prove it works.

## 1. Set it up first

On the control plane host, as root:

```bash
falak-ctl dr setup
```

It asks for (or takes as flags, see `falak-ctl dr setup --help`):

- an S3-compatible bucket: endpoint (`https://s3.eu-central-1.amazonaws.com`, `https://<account>.r2.cloudflarestorage.com`,
  …), bucket, region, key prefix (default `falak`), access key and secret key. Use a bucket of its own, in another
  provider or region than the control plane, with keys that can `PutObject`, `GetObject` and `ListBucket` there
  only. Secrets come from files (`--secret-key-file`, `--passphrase-file`) or the terminal, never the command line;
- a **DR passphrase** (12+ characters). Every uploaded backup is encrypted with it (AES-256, `openssl enc -pbkdf2`).
  Without it a backup can't be restored, so store it in your password manager, apart from the host;
- the schedule: every 6 hours by default (`--every 1|2|3|4|6|8|12|24`), and a monthly restore drill (`--drill monthly|off`).

It tests the bucket (writes `.falak-dr-check`, lists the prefix), stores the settings in `/opt/falak/dr/dr.env`
(directory `0700`, file `0600`, root only: never mounted into a container and never in a backup), and installs:

| Unit | Runs |
| --- | --- |
| `falak-backup.timer` → `falak-backup.service` | `falak-ctl backup --upload --scheduled` every N hours (persistent, 10 min jitter) |
| `falak-drill.timer` → `falak-drill.service` | `falak-ctl dr drill --scheduled` on the 1st of each month |

Then take a first backup and prove it:

```bash
falak-ctl backup --upload
falak-ctl dr drill
falak-ctl dr status
```

Until DR is configured, the panel's owners and admins (of the operator organization, see below) see a banner
("Remind me in 30 days" hides it for 30 days), a checklist item on the Projects page, and a weekly
`dr.not_configured` alert; `falak-ctl doctor` warns. Settings → Disaster recovery shows the last backup, its age and
size, the last drill and the commands; the configuration itself stays on the host. The panel reads
`/opt/falak/state/dr.json`, which falak-ctl writes and which holds no secrets.

Alerts (route them in Settings → Alert rules, group "Disaster recovery"):

| Type | When |
| --- | --- |
| `dr.not_configured` | weekly until `dr setup` ran |
| `dr.backup_failed` | a scheduled backup failed (once per failure) |
| `dr.backup_missing` | no backup for twice the schedule; cleared by the next backup |
| `dr.drill_failed` | a restore drill failed (once per drill) |

The **operator organization** is the oldest organization (the one install.sh's first admin created). On an install
shared by several organizations, set `FALAK_DR_ORGANIZATION=<organization id or slug>` in `custom.env`; members of
other organizations never see the control plane's DR.

## 2. What is in a backup

`falak-ctl backup` writes `/opt/falak/backups/falak-backup-<UTC time>[-label].tar.gz[.enc]`:

| Part | Why |
| --- | --- |
| `db.dump` | the database (`pg_dump -Fc`): servers, sites, users, the Fleet CA key, every secret (sealed under the KEK) |
| `falak-ca` volume | the Fleet CA certificate and the agent API certificate: agents trust only this CA |
| `edge-pki` volume | the edge's client certificate for the agent API |
| `app-storage`, `caddy-data` volumes | build artifacts and app files; ACME account and certificates |
| `registry-data` volume | only with `--include-registry` / `dr setup --include-registry` (images can be rebuilt) |
| `env`, `custom.env` | `APP_KEY`, database and Redis passwords, every setting |
| `secrets/kek`, `secrets/kek.previous` | the key-encryption key, **only in an encrypted backup**: there it is wrapped by the DR passphrase |
| `manifest` | Falak version, image digests, volumes, KEK id(s), row counts of the main tables, Docker version |

An unencrypted (local) backup leaves the KEK and the KMS / Vault credentials out and says so; it never leaves the
host (`--upload` refuses it). Uploads go to `s3://<bucket>/<prefix>/<name>.tar.gz.enc` with a `<name>.sha256` next to
them. The host keeps the newest `FALAK_BACKUP_KEEP` (14); expire old uploads with a bucket lifecycle rule (keep at
least as long as your oldest needed restore point, and at least one month).

## 3. The emergency kit

Keep, **off the host and apart from the backups**:

1. the DR passphrase;
2. the bucket's endpoint, name, region, prefix and a key that can read it;
3. `falak-ctl kek export <file>` (the KEK on its own; needed only for unencrypted backups, but cheap insurance).
   Export it again after every `falak-ctl kek rotate`.

With 1 and 2 a lost control plane comes back from the latest upload. Nothing in the bucket can be decrypted without 1.

## 4. The control plane host is lost

1. **Get a new VPS** (Ubuntu 22.04/24.04/26.04 or Debian 12, 2+ GB RAM).
2. **Point DNS at it:** the panel domain, `agents.<domain>`, `registry.<domain>` (and `grafana.<domain>`) — the same
   names as before. Agents keep calling `agents.<domain>`, so keep that name. (With `--skip-dns-check` you can
   install first and move DNS after.)
3. **Install with `--restore-from`**, same domain and e-mail:

   ```bash
   export FALAK_BACKUP_S3_ENDPOINT=https://s3.eu-central-1.amazonaws.com FALAK_BACKUP_S3_BUCKET=acme-falak-dr \
          FALAK_BACKUP_S3_REGION=eu-central-1 FALAK_BACKUP_S3_ACCESS_KEY=AKIA…
   curl -fsSL https://falak.sh/install.sh | sudo -E bash -s -- \
     --domain falak.example.com --email you@example.com --restore-from s3://latest
   ```

   What isn't in the environment (the secret key, the DR passphrase) is asked on the terminal without echo.
   `s3://falak-backup-20261010T060000Z-scheduled` restores a named backup instead of the latest. Install the same
   release as before or a newer one (`--version`): migrations bring an older dump forward, never back.

   The installer installs Docker and the release, pulls the images, then runs `falak-ctl restore s3://… --yes`:
   download, SHA-256 check, decrypt, then the backup's `.env`, KEK, database, Fleet CA, edge PKI and volumes
   replace the fresh install's (this host's version and source settings are kept). Only after the restore
   succeeded does it run `falak-ctl dr setup` with the same bucket and passphrase, so the schedule resumes —
   never earlier: a timer that ran on an empty install would upload it as the newest backup.
4. **Check:** `falak-ctl doctor`, `falak-ctl dr status`, then the panel: servers come back online on their own within
   a minute or two (their agents' mTLS certificates are signed by the restored Fleet CA; nothing is re-enrolled).
5. Writes after the backup are gone: deployments, settings and new secrets since then. Redeploy what changed.

Without the installer (an existing Falak install, e.g. a standby host): `falak-ctl restore s3://latest --yes` with
the `FALAK_BACKUP_S3_*` / `FALAK_BACKUP_PASSPHRASE` variables in the environment, or after `falak-ctl dr setup`.
With a backup file instead of the bucket: `falak-ctl restore <file> --yes`. An unencrypted backup has no KEK: import
it first (`falak-ctl kek import <kit> --force`); restore refuses otherwise.

## 5. An application server is lost

Servers → the lost server → **This server is gone…** (owners and admins; `recovery.servers`).

1. **Pick a replacement**: any other active server of the organization. For a new machine, create it first (Servers →
   New server, through a provider or with the install command), wait until it is active, then come back. A server
   that needs attention can be reprovisioned from its page; the recovery waits for it.
2. **Read the dry run**: every database container with each database's latest restorable backup and the **data
   loss** (time since that backup; "all data" when there is none), every volume the same way, the services that ran
   there, and the domains: which ones Falak moves (Cloudflare zones it manages) and which need a DNS change by hand.
   Nothing changes yet.
3. **Confirm** by typing the lost server's name. The steps then run in order; each one starts when the one before it
   finished, and the page follows them live:

   | Step | What happens |
   | --- | --- |
   | Replacement server | waits until it is active |
   | Move services | each site / compose stack / function gets the replacement instead of the lost server (the leader moves too); it is prepared there, edge routes and Falak-managed DNS records follow; nothing is deployed yet |
   | Restore databases | each container is recreated on the replacement — same name and DNS name (`falak-db-<id>`), password, settings, limits, users and schedules; a new data volume and host port — then each database is restored from its latest backup |
   | Restore volumes | each volume's latest backup goes into a volume of the same name on the replacement; its attachments move to it |
   | Redeploy services | every service that had been deployed is redeployed onto the restored data |
   | Domains | Falak-managed records are checked; the others are listed with the replacement's address |

   A failed item stops the recovery at its step; fix the cause (e.g. install the PHP version a site needs on the
   replacement) and **Retry** the step: only what failed runs again.
4. Afterwards delete the lost server in Falak (its rows for the old volumes go with it), and the machine at its
   provider if it still exists.

Not automatic: databases or volumes whose backups use **your own age key** (they need your identity — restore them
from the Databases / Volumes page; the wizard marks them "Your turn"), and DNS records Falak doesn't manage.
Point-in-time recovery: the estimate shown is the latest full backup's age, the upper bound of the loss; the wizard
restores that backup.

### Readiness

**Disaster recovery readiness** (⌘K → "Disaster recovery readiness", or `/recovery/readiness`) scores each project:
every database backed up (backups are always encrypted), on a schedule and proven by a restore drill, PITR on for
production SQL databases, every volume backed up on a schedule. Each gap links to where it is fixed.

## 6. Drills

- **Control plane:** `falak-ctl dr drill` (monthly from `falak-drill.timer` when enabled). It downloads the latest
  upload (or `--local` for the newest local backup, `--backup <file>` for one), decrypts it, and restores it into a
  throwaway compose project `falak-drill` in `/opt/falak/drill`: its own `.env`, secrets, volumes, network and edge
  subnet (`10.213.78.0/24`, `FALAK_DRILL_SUBNET`), ports 18080/18443, this host's images. Only postgres, valkey and
  the control plane run — no edge, workers or scheduler, so nothing in it talks to your servers or sends mail. It
  checks that the control plane starts on the data (migrations run), that `migrate:status` has nothing pending,
  that `falak:keys:check` unwraps every data key with the backup's KEK, that the row counts match the manifest, and
  that the Fleet CA is there; then removes the project, its volumes and directory, whatever the outcome. The result
  goes to `state/dr.json` (the panel, `dr.drill_failed`). It needs about 1.5 GB of free memory while it runs.
- **Databases and volumes:** restore drills per backup schedule (Databases → Backups, Volumes → Backups), on another
  server when one is set. They feed the readiness score.
- **Once a year, for real:** restore the control plane onto a scratch VPS with `--restore-from` (firewalled off, or
  with its agent API unreachable, so the real agents keep talking to the real control plane), and run "This server
  is gone" for a staging server.
