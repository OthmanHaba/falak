#!/usr/bin/env bash
# Scripted test for falak-ctl prune-images: removes only Falak images other than the current and previous version,
# never third-party images, and keeps images Docker refuses to remove (in use). Docker is stubbed; no daemon needed.
#   deploy/tests/falak-ctl-prune-images.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-prune.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.5.0\nFALAK_PREVIOUS_VERSION=v0.4.3\n' > "$FALAK_DIR/.env"
rm_log="$work/removed"; : > "$rm_log"

# shellcheck source=/dev/null
. "$here/../falak-ctl"

docker() { # docker image ls --format F REPO | docker image rm REF
  case "$1 $2" in
    "image ls")
      case "$5" in
        ghcr.io/acme/falak-control-plane) printf 'v0.5.0 c5\nv0.4.3 c4\nv0.4.2 c3\nv0.4.1 c2\n<none> c1\n' ;;
        ghcr.io/acme/falak-edge) printf 'v0.5.0 e5\nv0.4.0 e0\n' ;;
        ghcr.io/acme/falak-builder) printf 'v0.4.2 b3\n' ;;
      esac ;;
    "image rm")
      [ "$3" != "ghcr.io/acme/falak-edge:v0.4.0" ] || return 1 # "in use by a container"
      printf '%s\n' "$3" >> "$rm_log" ;;
    *) fail "unexpected docker $*" ;;
  esac
}

prune_images --dry-run >/dev/null
[ ! -s "$rm_log" ] || fail "--dry-run removed images: $(cat "$rm_log")"
pass "--dry-run removes nothing"

prune_images >/dev/null 2>&1
expected="$(printf '%s\n' ghcr.io/acme/falak-control-plane:v0.4.2 ghcr.io/acme/falak-control-plane:v0.4.1 c1 ghcr.io/acme/falak-builder:v0.4.2)"
[ "$(cat "$rm_log")" = "$expected" ] || fail "removed: $(cat "$rm_log") (expected: $expected)"
pass "removes old Falak images, keeps current + previous, survives an image in use"

: > "$rm_log"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\n' > "$FALAK_DIR/.env"
prune_images >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned without FALAK_VERSION"
pass "does nothing without FALAK_VERSION"

printf 'FALAK_VERSION=v0.5.0\n' > "$FALAK_DIR/.env"
prune_images >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned without FALAK_IMAGE_PREFIX"
pass "does nothing without FALAK_IMAGE_PREFIX"

: > "$rm_log"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.5.0\nFALAK_PULL=0\n' > "$FALAK_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "auto-pruned locally built images (FALAK_PULL=0): $(cat "$rm_log")"
[ "$(env_get FALAK_PREVIOUS_VERSION)" = "v0.4.3" ] || fail "FALAK_PREVIOUS_VERSION not recorded"
pass "an update with FALAK_PULL=0 keeps every image but records the rollback target"

printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.5.0\nFALAK_PREVIOUS_VERSION=v0.4.3\nFALAK_PRUNE_IMAGES=1\n' > "$FALAK_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ -s "$rm_log" ] || fail "an update with pulled images did not prune"
pass "an update with pulled images prunes"

: > "$rm_log"
printf 'FALAK_IMAGE_PREFIX=ghcr.io/acme\nFALAK_VERSION=v0.5.0\nFALAK_PRUNE_IMAGES=0\n' > "$FALAK_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned with FALAK_PRUNE_IMAGES=0"
pass "FALAK_PRUNE_IMAGES=0 keeps every image"
