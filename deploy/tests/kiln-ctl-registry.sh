#!/usr/bin/env bash
# Scripted test for the built-in registry settings in kiln-ctl: installs that predate the registry get a host,
# URL and credentials once (never rotated afterwards), custom HTTPS ports end up in the URL, and `domain set`
# moves the host while keeping the old one as an alias. Docker is not needed.
#   deploy/tests/kiln-ctl-registry.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/kiln-ctl-registry.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export KILN_DIR="$work/kiln" KILN_CTL_SOURCED=1
mkdir -p "$KILN_DIR"
printf 'KILN_DOMAIN=kiln.example.com\nKILN_AGENT_API_HOST=agents.kiln.example.com\n' > "$KILN_DIR/.env"

# shellcheck source=/dev/null
. "$here/../kiln-ctl"

ensure_registry_env >/dev/null
[ "$(env_get KILN_REGISTRY_HOST)" = registry.kiln.example.com ] || fail "host: $(env_get KILN_REGISTRY_HOST)"
[ "$(env_get KILN_REGISTRY_URL)" = registry.kiln.example.com ] || fail "url: $(env_get KILN_REGISTRY_URL)"
[ "$(env_get KILN_REGISTRY_USERNAME)" = kiln ] || fail "username: $(env_get KILN_REGISTRY_USERNAME)"
password="$(env_get KILN_REGISTRY_PASSWORD)"
[ "${#password}" = 48 ] || fail "password length ${#password}"
[ "$(stat -c %a "$KILN_DIR/.env" 2>/dev/null || stat -f %Lp "$KILN_DIR/.env")" = 600 ] || fail ".env is not mode 600"
pass "an install without a registry gets host, URL and credentials"

ensure_registry_env >/dev/null
[ "$(env_get KILN_REGISTRY_PASSWORD)" = "$password" ] || fail "password rotated on a second run"
[ "$(grep -c '^KILN_REGISTRY_HOST=' "$KILN_DIR/.env")" = 1 ] || fail "duplicate KILN_REGISTRY_HOST lines"
pass "a second run changes nothing"

printf 'KILN_DOMAIN=kiln.example.com\nKILN_HTTPS_PORT=8443\n' > "$KILN_DIR/.env"
ensure_registry_env >/dev/null
[ "$(env_get KILN_REGISTRY_URL)" = registry.kiln.example.com:8443 ] || fail "url with port: $(env_get KILN_REGISTRY_URL)"
pass "a custom HTTPS port is part of the registry URL"

printf 'KILN_DOMAIN=kiln.example.com\nKILN_REGISTRY_HOST=images.example.com\nKILN_REGISTRY_URL=images.example.com\n' > "$KILN_DIR/.env"
ensure_registry_env >/dev/null
[ "$(env_get KILN_REGISTRY_HOST)" = images.example.com ] || fail "custom host replaced"
[ -n "$(env_get KILN_REGISTRY_PASSWORD)" ] || fail "missing password not generated"
pass "a custom registry host is kept; missing credentials are added"

# domain set: the registry follows the panel, the old name stays served as an alias.
printf 'KILN_DOMAIN=old.example.com\nKILN_AGENT_API_HOST=agents.old.example.com\nKILN_REGISTRY_HOST=registry.old.example.com\nKILN_REGISTRY_URL=registry.old.example.com\nKILN_REGISTRY_USERNAME=kiln\nKILN_REGISTRY_PASSWORD=secret\n' > "$KILN_DIR/.env"
require_install() { :; }
cmd_backup() { :; }
wait_healthy() { :; }
health_check() { :; }
cmd_domain set new.example.com >/dev/null
[ "$(env_get KILN_REGISTRY_HOST)" = registry.new.example.com ] || fail "domain set host: $(env_get KILN_REGISTRY_HOST)"
[ "$(env_get KILN_REGISTRY_URL)" = registry.new.example.com ] || fail "domain set url: $(env_get KILN_REGISTRY_URL)"
[ "$(env_get KILN_REGISTRY_HOST_ALIASES)" = registry.old.example.com ] || fail "aliases: $(env_get KILN_REGISTRY_HOST_ALIASES)"
[ "$(env_get KILN_REGISTRY_PASSWORD)" = secret ] || fail "domain set rotated the password"
pass "domain set moves the registry host and keeps the old one as an alias"
