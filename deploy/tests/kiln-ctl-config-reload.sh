#!/usr/bin/env bash
# Scripted test for kiln-ctl: an update that changes a bind-mounted config file must recreate exactly the
# services that mount it (the directory swap otherwise leaves them on the old inode). Needs Docker.
#   deploy/tests/kiln-ctl-config-reload.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/kiln-ctl-test.XXXXXX")"
work="$(cd "$work" && pwd -P)"
project="kilnctltest$$"
image="${KILN_TEST_IMAGE:-busybox:1.37}"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

bundle() { # bundle DIR LOKI_CONTENT DASHBOARD_CONTENT
  mkdir -p "$1/deploy" "$1/observability/loki" "$1/observability/tempo" "$1/observability/grafana/dashboards"
  printf '%s\n' "$2" > "$1/observability/loki/loki.yaml"
  printf 'tempo: 1\n' > "$1/observability/tempo/tempo.yaml"
  printf '%s\n' "$3" > "$1/observability/grafana/dashboards/app.json"
  cat > "$1/deploy/compose.yml" <<YAML
services:
  loki:
    image: $image
    command: ["sleep", "600"]
    volumes: ["../observability/loki/loki.yaml:/etc/loki/loki.yaml:ro"]
  tempo:
    image: $image
    command: ["sleep", "600"]
    volumes: ["../observability/tempo/tempo.yaml:/etc/tempo/tempo.yaml:ro"]
  grafana:
    image: $image
    command: ["sleep", "600"]
    volumes: ["../observability/grafana/dashboards:/var/lib/grafana/dashboards/kiln:ro"]
  plain:
    image: $image
    command: ["sleep", "600"]
YAML
}

export KILN_DIR="$work/kiln" KILN_PROJECT="$project" KILN_CTL_SOURCED=1
cleanup() {
  docker compose -p "$project" -f "$KILN_DIR/deploy/compose.yml" down -t 0 >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

docker image inspect "$image" >/dev/null 2>&1 || docker pull -q "$image" >/dev/null
bundle "$KILN_DIR" 'loki: v1' '{"v": 1}'
printf 'COMPOSE_PROFILES=\n' > "$KILN_DIR/.env"

# shellcheck source=/dev/null
. "$here/../kiln-ctl"

compose up -d --wait >/dev/null 2>&1
ids() { compose ps --format '{{.Service}}={{.ID}}' | sort; }
before="$(ids)"

[ -z "$(mount_drift)" ] || fail "fresh containers must not drift: $(mount_drift)"
pass "fresh stack has no drift"

# An update: new bundle staged, swapped into place (loki.yaml + a dashboard change, tempo.yaml unchanged).
stage="$work/stage"
bundle "$stage" 'loki: v2' '{"v": 2}'
install_bundle "$stage"

[ "$(compose exec -T loki cat /etc/loki/loki.yaml)" = 'loki: v1' ] || fail "precondition: loki should still see v1"
drift="$(mount_drift | sort -u | tr '\n' ' ')"
[ "$drift" = "grafana loki " ] || fail "drift should be grafana+loki, got '$drift'"
pass "drift detected for exactly the services mounting changed files ($drift)"

out="$(wait_healthy 120 2>&1)"
after="$(ids)"
printf '%s\n' "$out" | grep -q 'recreated grafana loki' || fail "wait_healthy should report the recreated services: $out"
[ "$(compose exec -T loki cat /etc/loki/loki.yaml)" = 'loki: v2' ] || fail "loki must see the new loki.yaml"
[ "$(compose exec -T grafana cat /var/lib/grafana/dashboards/kiln/app.json)" = '{"v": 2}' ] || fail "grafana must see the new dashboard"
for svc in loki grafana; do
  [ "$(grep "^$svc=" <<<"$before")" != "$(grep "^$svc=" <<<"$after")" ] || fail "$svc was not recreated"
done
for svc in tempo plain; do
  [ "$(grep "^$svc=" <<<"$before")" = "$(grep "^$svc=" <<<"$after")" ] || fail "$svc must not be recreated"
done
pass "update recreated loki+grafana only; they see the new files"

# Unchanged mounts stay on the previous bundle's inodes. A single file keeps its content even once deleted, but
# a directory mount (grafana) goes empty when a later update removes that bundle (*.prev): it must be recreated.
bundle "$work/stage2" 'loki: v2' '{"v": 2}'
install_bundle "$work/stage2"   # grafana's directory is now observability.prev
[ -z "$(mount_drift)" ] || fail "same content after a swap is not drift, got '$(mount_drift)'"
bundle "$work/stage3" 'loki: v2' '{"v": 2}'
install_bundle "$work/stage3"   # removes observability.prev: grafana's mounted directory is emptied
drift="$(mount_drift | sort -u | tr '\n' ' ')"
[ "$drift" = "grafana " ] || fail "an emptied directory mount must drift, got '$drift'"
recreate_drifted 120 >/dev/null 2>&1
[ -z "$(mount_drift)" ] || fail "no drift after reload-configs"
[ "$(compose exec -T grafana cat /var/lib/grafana/dashboards/kiln/app.json)" = '{"v": 2}' ] || fail "grafana must see its dashboards again"
pass "a later update repairs directory mounts emptied by removing the previous bundle"
