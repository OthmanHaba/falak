# Falak (فلك) rename — record

Status: done on `feat/falak` (2026-10-05) · Target: **v0.8.0 "Falak"** · Domain **falak.sh** · Repos
`OthmanHaba/falak`, `OthmanHaba/falak-website`

The product was renamed to Falak before its first public release. Nothing was published under the previous name,
so the rename is **clean**: no aliases for old environment variables, headers, paths, commands or packages, no
migration of existing installs, no compatibility shims. `tools/check-brand.sh` (run in CI, control-plane quality
job) fails if the previous name appears in any tracked file or path.

## What was renamed

Everything that carried the product name, in one mechanical pass followed by hand fixes:

| Area | New names |
|---|---|
| PHP | namespaces `Falak\…` (21 modules, `Falak\Apm`), `FalakNavigation`, `FalakAdjustments`, `FalakPlaceholders`, Artisan `falak:*` |
| Go | module `github.com/OthmanHaba/falak/agent`; binaries `falak`, `falak-agent`, `falak-builder` |
| UI | `resources/js/components/falak`, `lib/falak.ts`, Inertia prop `falak`, storage keys `falak:*`, logo/wordmark, `APP_NAME=Falak` |
| Control-plane install | compose project/volumes `falak`, Postgres db/user `falak`, `/opt/falak`, `falak-ctl`, `FALAK_*` env, `falak-deploy.tar.gz` |
| Managed servers | `/etc/falak`, `/var/lib/falak`, `/run/falak`, `/var/log/falak`, `/srv/falak/sites`, Linux user `falak`, units `falak-agent`, `falak-edge`, `falak-firewall`, `falak-fn-gateway`, `falak-cloudflared`, `falak-builder`, nft `table inet falak`, `wg-falak`, Redis/Valkey `falak-<engine>-<name>` |
| Protocol | `X-Falak-*` headers, User-Agent `falak-agent/<v>`, schema `$id` `https://falak.sh/agent-protocol/…` |
| User contracts | `FALAK_*` env injected into apps/hooks/functions, `$FALAK_FETCH/ACTIVATE/RESTART_PROCS`, `${{ falak.* }}` template placeholders, `falak-fn-*` runtimes, `.falakignore`, `.falak-function.json`, CLI config `~/.config/falak` |
| Telemetry | `falak.*` OTLP attributes, `falak_*` labels, Grafana uids `falak-*`, folders `falak-org-<id>` |
| Packages | `falak/apm-laravel`, `@falak/apm-node` |
| Release | `ghcr.io/othmanhaba/falak-{control-plane,builder,edge,fn-*}`, assets `falak-agent-*`, `falak-builder-*`, `falak-{linux,darwin}-*`, `falak-deploy.tar.gz`, `falak-ctl`, `install.sh`, `install-cli.sh` |

## Method

1. A one-off script rewrote file contents and renamed paths (`git mv`, deepest first), skipping `vendor/`,
   `node_modules/`, binaries and `.gitleaksignore`. Ordered, case-preserving token map: the Go module path →
   `github.com/OthmanHaba/falak/agent`; the repo and website repo → `OthmanHaba/falak`, `OthmanHaba/falak-website`
   (`othmanhaba/falak` for GHCR); the old schema domain → `falak.sh`; then UPPER / Title / lower case.
2. Lockfiles: only the `sim/fixtures/apps/laravel-demo` lock named the product; it was regenerated with
   `composer update falak/apm-laravel`. The bun lockfiles carry only the package name (`bun install
   --frozen-lockfile` accepts them).
3. Hand fixes:
   - Hashed Redis/Valkey instance users (`falak-rh-` / `falak-vh-` + hash) are cut to 32 characters (23 hex
     digits): the longer prefix would otherwise exceed useradd's limit. Plain names (`falak-redis-<name>`) still
     fall back to the hash past 32 characters.
   - Checked the other length limits: Grafana folder uids `falak-org-<ulid>` are 36 characters (limit 40), dashboard
     uids stay short, `wg-falak` fits the 15-character interface limit. Site Linux users still reserve the `falak`
     prefix (such slugs get `s-`).
   - gofmt, Pint (import order) and Prettier reflow; ASCII diagrams and aligned comment columns realigned.
4. The installer one-liner stays the GitHub raw URL
   (`https://raw.githubusercontent.com/OthmanHaba/falak/main/deploy/install.sh`); `https://falak.sh/install.sh` is
   documented as the short form once the website serves it.

## Release checklist

- Rename the GitHub repos to `OthmanHaba/falak` and `OthmanHaba/falak-website` before tagging, so release URLs,
  `FALAK_REPO_DEFAULT` and GHCR image names (`ghcr.io/othmanhaba/falak-*`) match.
- Make the new GHCR packages public after the first release workflow run.
- Point `falak.sh` at the website and serve `/install.sh` (and `/install-cli.sh`) from it.
