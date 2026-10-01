#!/usr/bin/env bash
# Scripted test for kiln-ctl prune-images: removes only Kiln images other than the current and previous version,
# never third-party images, and keeps images Docker refuses to remove (in use). Docker is stubbed; no daemon needed.
#   deploy/tests/kiln-ctl-prune-images.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/kiln-ctl-prune.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export KILN_DIR="$work/kiln" KILN_CTL_SOURCED=1
mkdir -p "$KILN_DIR"
printf 'KILN_IMAGE_PREFIX=ghcr.io/acme\nKILN_VERSION=v0.5.0\nKILN_PREVIOUS_VERSION=v0.4.3\n' > "$KILN_DIR/.env"
rm_log="$work/removed"; : > "$rm_log"

# shellcheck source=/dev/null
. "$here/../kiln-ctl"

docker() { # docker image ls --format F REPO | docker image rm REF
  case "$1 $2" in
    "image ls")
      case "$5" in
        ghcr.io/acme/kiln-control-plane) printf 'v0.5.0 c5\nv0.4.3 c4\nv0.4.2 c3\nv0.4.1 c2\n<none> c1\n' ;;
        ghcr.io/acme/kiln-edge) printf 'v0.5.0 e5\nv0.4.0 e0\n' ;;
        ghcr.io/acme/kiln-builder) printf 'v0.4.2 b3\n' ;;
      esac ;;
    "image rm")
      [ "$3" != "ghcr.io/acme/kiln-edge:v0.4.0" ] || return 1 # "in use by a container"
      printf '%s\n' "$3" >> "$rm_log" ;;
    *) fail "unexpected docker $*" ;;
  esac
}

prune_images --dry-run >/dev/null
[ ! -s "$rm_log" ] || fail "--dry-run removed images: $(cat "$rm_log")"
pass "--dry-run removes nothing"

prune_images >/dev/null 2>&1
expected="$(printf '%s\n' ghcr.io/acme/kiln-control-plane:v0.4.2 ghcr.io/acme/kiln-control-plane:v0.4.1 c1 ghcr.io/acme/kiln-builder:v0.4.2)"
[ "$(cat "$rm_log")" = "$expected" ] || fail "removed: $(cat "$rm_log") (expected: $expected)"
pass "removes old Kiln images, keeps current + previous, survives an image in use"

: > "$rm_log"
printf 'KILN_IMAGE_PREFIX=ghcr.io/acme\n' > "$KILN_DIR/.env"
prune_images >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned without KILN_VERSION"
pass "does nothing without KILN_VERSION"

printf 'KILN_VERSION=v0.5.0\n' > "$KILN_DIR/.env"
prune_images >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned without KILN_IMAGE_PREFIX"
pass "does nothing without KILN_IMAGE_PREFIX"

: > "$rm_log"
printf 'KILN_IMAGE_PREFIX=ghcr.io/acme\nKILN_VERSION=v0.5.0\nKILN_PULL=0\n' > "$KILN_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "auto-pruned locally built images (KILN_PULL=0): $(cat "$rm_log")"
[ "$(env_get KILN_PREVIOUS_VERSION)" = "v0.4.3" ] || fail "KILN_PREVIOUS_VERSION not recorded"
pass "an update with KILN_PULL=0 keeps every image but records the rollback target"

printf 'KILN_IMAGE_PREFIX=ghcr.io/acme\nKILN_VERSION=v0.5.0\nKILN_PREVIOUS_VERSION=v0.4.3\nKILN_PRUNE_IMAGES=1\n' > "$KILN_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ -s "$rm_log" ] || fail "an update with pulled images did not prune"
pass "an update with pulled images prunes"

: > "$rm_log"
printf 'KILN_IMAGE_PREFIX=ghcr.io/acme\nKILN_VERSION=v0.5.0\nKILN_PRUNE_IMAGES=0\n' > "$KILN_DIR/.env"
after_update_images v0.4.3 v0.5.0 >/dev/null 2>&1
[ ! -s "$rm_log" ] || fail "pruned with KILN_PRUNE_IMAGES=0"
pass "KILN_PRUNE_IMAGES=0 keeps every image"
