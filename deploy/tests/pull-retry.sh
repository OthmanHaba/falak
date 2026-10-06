#!/usr/bin/env bash
# Scripted test for image pull retries in falak-ctl (update, up) and install.sh: transient registry errors
# ("connection reset by peer") are retried with backoff, a lasting failure gives up after FALAK_PULL_ATTEMPTS,
# and FALAK_PULL=0 pulls nothing. Docker and sleep are stubbed; no daemon or waiting needed.
#   deploy/tests/pull-retry.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-pull-retry.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.9.0\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"

calls="$work/calls"; slept="$work/slept"
failures=0 # how many pulls fail before one succeeds
docker() { # docker compose -p P --env-file F -f F pull --quiet [--policy missing]
  [ "$1 $8" = "compose pull" ] || fail "unexpected docker $*"
  shift 7; printf '%s\n' "$*" >> "$calls"
  local n; n="$(wc -l < "$calls")"
  if [ "$n" -le "$failures" ]; then
    printf 'Error response from daemon: Get "https://ghcr.io/v2/": read tcp [2a01::1]:443: connection reset by peer\n' >&2
    return 1
  fi
}
sleep() { printf '%s\n' "$1" >> "$slept"; }
reset() { : > "$calls"; : > "$slept"; failures="$1"; }

reset 0
pull_images 2>/dev/null || fail "a pull that works failed"
[ "$(cat "$calls")" = "pull --quiet" ] || fail "calls: $(cat "$calls")"
[ ! -s "$slept" ] || fail "slept without a failure"
pass "a working pull runs once"

reset 2
pull_images 2>"$work/err" || fail "two transient failures were not retried"
[ "$(wc -l < "$calls" | tr -d ' ')" = 3 ] || fail "attempts: $(wc -l < "$calls")"
[ "$(tr '\n' ' ' < "$slept")" = "5 10 " ] || fail "backoff: $(tr '\n' ' ' < "$slept")"
grep -q "image pull failed (attempt 1 of 4" "$work/err" || fail "no retry message: $(cat "$work/err")"
pass "transient failures are retried with backoff (5s, 10s) and say so"

reset 99
if pull_images 2>"$work/err"; then fail "a lasting failure succeeded"; fi
[ "$(wc -l < "$calls" | tr -d ' ')" = 4 ] || fail "attempts: $(wc -l < "$calls")"
[ "$(tr '\n' ' ' < "$slept")" = "5 10 20 " ] || fail "backoff: $(tr '\n' ' ' < "$slept")"
grep -q "image pull failed 4 times, giving up" "$work/err" || fail "no give-up message: $(cat "$work/err")"
pass "a lasting failure gives up after 4 attempts"

reset 99
printf 'FALAK_PULL_ATTEMPTS=2\n' >> "$FALAK_DIR/.env"
if pull_images 2>/dev/null; then fail "a lasting failure succeeded"; fi
[ "$(wc -l < "$calls" | tr -d ' ')" = 2 ] || fail "FALAK_PULL_ATTEMPTS=2: $(wc -l < "$calls") attempts"
pass "FALAK_PULL_ATTEMPTS in .env bounds the attempts"

printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.9.0\n' > "$FALAK_DIR/.env"
reset 1
pull_images missing 2>/dev/null || fail "up: a transient failure was not retried"
[ "$(sort -u "$calls")" = "pull --quiet --policy missing" ] || fail "up pulled: $(cat "$calls")"
pass "up pulls only missing images, with the same retries"

reset 0
printf 'FALAK_PULL=0\n' >> "$FALAK_DIR/.env"
pull_images >/dev/null 2>&1
[ ! -s "$calls" ] || fail "FALAK_PULL=0 pulled"
pass "FALAK_PULL=0 pulls nothing"

# install.sh: the same helper around its `docker compose pull` (dc).
(
  export FALAK_INSTALL_SOURCED=1 FALAK_DOMAIN=falak.example.com FALAK_EMAIL=ops@example.com FALAK_PULL_ATTEMPTS=3
  # shellcheck source=/dev/null
  . "$here/../install.sh"
  reset 1
  pull_retry dc pull --quiet 2>/dev/null || fail "install.sh: a transient failure was not retried"
  [ "$(cat "$calls")" = "$(printf 'pull --quiet\npull --quiet')" ] || fail "install.sh calls: $(cat "$calls")"
  [ "$(cat "$slept")" = 5 ] || fail "install.sh backoff: $(cat "$slept")"
  reset 99
  if pull_retry dc pull --quiet 2>/dev/null; then fail "install.sh: a lasting failure succeeded"; fi
  [ "$(wc -l < "$calls" | tr -d ' ')" = 3 ] || fail "install.sh: FALAK_PULL_ATTEMPTS=3 gave $(wc -l < "$calls") attempts"
)
pass "install.sh retries its image pull the same way"
