#!/usr/bin/env bash
# Smoke test of a restore drill's container hardening (agent/internal/db/drill.go drillBody): the image starts healthy
# and restores a logical backup with every capability dropped but the ones its entrypoint needs, no-new-privileges, a
# PID limit, one CPU and no network.
#   IMAGE=falak-postgres:17-local images/db/drill-test.sh postgres 17
#   images/db/drill-test.sh mysql 8.4          # builds the image first (images/db/build.sh)
set -euo pipefail
engine=${1:?engine (postgres|mysql|mariadb|redis|valkey)}
version=${2:?version}
here=$(cd "$(dirname "$0")" && pwd)
if [ -z "${IMAGE:-}" ]; then
	IMAGE=$("$here/build.sh" "$engine" "$version" --quiet | tail -n1)
fi

# Keep in sync with drillCapAdd in agent/internal/db/drill.go.
caps=(CHOWN DAC_OVERRIDE FOWNER SETUID SETGID)

name="fdbd-$engine-${version//./}-$$"
work=$(mktemp -d)
cleanup() {
	local rc=$?
	if [ "$rc" -ne 0 ]; then
		docker logs --tail 40 "$name" >&2 2>&1 || true
	fi
	docker rm -f "$name" >/dev/null 2>&1 || true
	docker volume rm -f "$name-data" >/dev/null 2>&1 || true
	rm -rf "$work"
	exit "$rc"
}
trap cleanup EXIT

case "$engine" in
postgres)
	data=/var/lib/postgresql/data
	[ "${version%%.*}" -ge 18 ] && data=/var/lib/postgresql
	pwenv=POSTGRES_PASSWORD_FILE
	;;
mysql) data=/var/lib/mysql pwenv=MYSQL_ROOT_PASSWORD_FILE ;;
mariadb) data=/var/lib/mysql pwenv=MARIADB_ROOT_PASSWORD_FILE ;;
*) data=/data pwenv=FALAK_DB_PASSWORD_FILE ;;
esac
mkdir -p "$work/secrets" "$work/conf"
printf 'drill-%s' "$RANDOM$RANDOM" >"$work/secrets/password"
chmod 0444 "$work/secrets/password"
printf '{"tls":false}' >"$work/conf/settings.json"

args=(--cap-drop ALL --security-opt no-new-privileges --pids-limit 512 --cpus 1 --memory 512m --network none)
for c in "${caps[@]}"; do args+=(--cap-add "$c"); done

docker volume create "$name-data" >/dev/null
docker run -d --name "$name" "${args[@]}" \
	-v "$name-data:$data" -v "$work/secrets:/run/secrets:ro" -v "$work/conf:/run/falak/db/conf:ro" \
	-e FALAK_DB_SETTINGS_FILE=/run/falak/db/conf/settings.json -e "$pwenv=/run/secrets/password" "$IMAGE" >/dev/null

for _ in $(seq 1 90); do
	state=$(docker inspect -f '{{.State.Health.Status}}' "$name" 2>/dev/null || echo gone)
	[ "$state" = healthy ] && break
	[ "$state" = gone ] && { echo "FAIL: container stopped" >&2; exit 1; }
	sleep 2
done
[ "$state" = healthy ] || { echo "FAIL: not healthy ($state)" >&2; exit 1; }
echo "ok: healthy with cap-drop ALL + ${caps[*]}, no-new-privileges, pids 512"

case "$engine" in
redis | valkey)
	docker exec "$name" falak-db table-counts | grep -q tables
	;;
*)
	docker exec "$name" falak-db database create --name app >/dev/null
	docker exec "$name" falak-db backup logical --database app --out - >"$work/app.dump" 2>/dev/null
	docker exec "$name" falak-db database create --name app_copy >/dev/null
	docker exec -i "$name" falak-db restore logical --database app_copy --in - <"$work/app.dump" >/dev/null
	docker exec "$name" falak-db table-counts --database app_copy | grep -q tables
	printf 'SELECT 1' | docker exec -i "$name" falak-db query --database app_copy | grep -q '"rows":1'
	;;
esac
echo "PASS drill hardening $engine $version"
