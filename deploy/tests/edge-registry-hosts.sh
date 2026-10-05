#!/usr/bin/env bash
# Scripted test for the registry host check in the edge entrypoint: FALAK_REGISTRY_HOST / _ALIASES are rendered into
# the Caddyfile, so only DNS names (comma-separated for aliases) pass. Runs valid_hosts under sh; Docker not needed.
#   deploy/tests/edge-registry-hosts.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

fn="$(sed -n '/^valid_hosts() {/,/^}/p' "$here/../edge/entrypoint.sh")"
[ -n "$fn" ] || fail "valid_hosts not found in edge/entrypoint.sh"
check() { sh -c "$fn"'
valid_hosts "$1"' sh "$1"; }

for good in registry.falak.example.com registry-1.example.co.uk localhost 'registry.old.example.com,registry.older.example.com' 'a.example.com,b.example.com,c.example.com'; do
  check "$good" || fail "rejected: $good"
done
pass "host names and comma-separated aliases pass"

nl='
'
for bad in '' 'registry.example.com {' "registry.example.com${nl}evil.example.com" 'a.example.com, b.example.com' '*.example.com' 'registry.example.com:5000' '[::1]' \
  'a.example.com,' ',a.example.com' 'a.example.com,,b.example.com' '.example.com' 'example.com.' 'a..example.com' '-a.example.com' 'a-.example.com' \
  'a.-b.example.com' 'reg"istry.example.com' 'registry.example.com#x' 'https://registry.example.com' "$(printf 'a%.0s' $(seq 1 254))"; do
  if check "$bad"; then fail "accepted: $(printf '%q' "$bad")"; fi
done
pass "Caddy syntax, spaces, wildcards, ports, IP literals and malformed names are refused"

# The entrypoint refuses to start with such a value (before anything is rendered).
# shellcheck disable=SC2016  # literal source text
grep -q 'valid_hosts "$FALAK_REGISTRY_HOST"' "$here/../edge/entrypoint.sh" || fail "FALAK_REGISTRY_HOST is not checked"
# shellcheck disable=SC2016  # literal source text
grep -q 'valid_hosts "$FALAK_REGISTRY_HOST_ALIASES"' "$here/../edge/entrypoint.sh" || fail "FALAK_REGISTRY_HOST_ALIASES is not checked"
pass "the entrypoint checks both values"
