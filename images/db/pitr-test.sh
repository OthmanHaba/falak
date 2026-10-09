#!/usr/bin/env bash
# Point-in-time recovery, the whole flow, with the agent's own code against real containers.
#
#   images/db/pitr-test.sh <engine> <version>     # build (images/db/build.sh), then test
#   IMAGE=falak-mysql:8.4-local images/db/pitr-test.sh mysql 8.4   # test an image that is already loaded
#
# The agent creates a source instance with PITR on (its image pulled by digest, like on a server: the image is pushed
# to a throwaway local registry first), rows go in, a base backup is taken, more rows, a time T is noted, DROP TABLE;
# the shipper sends the spool through a fake control plane (per-segment keys) to a fake S3 (httptest); then
# db.pitr.restore rebuilds a NEW instance at T: the dropped rows are back, it is read-only until db.pitr.promote.
# (agent/internal/db/pitr_e2e_test.go, build tag pitre2e). Needs Docker, Go and jq.
set -euo pipefail

engine=${1:?engine (postgres|mysql|mariadb)}
version=${2:?version}
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
port=${REGISTRY_PORT:-5055}
registry="falak-pitr-registry-$port"

if [ -z "${IMAGE:-}" ]; then
	IMAGE=$("$here/build.sh" "$engine" "$version" --quiet | tail -n1)
fi

work=$(mktemp -d)
started_registry=
cleanup() {
	local rc=$?
	if [ -n "$started_registry" ] && [ -z "${KEEP_REGISTRY:-}" ]; then
		docker rm -f "$registry" >/dev/null 2>&1 || true
	fi
	rm -rf "$work"
	exit "$rc"
}
trap cleanup EXIT

# Servers pull database images by digest and check it: push the image to a local registry to get one.
if ! docker inspect "$registry" >/dev/null 2>&1; then
	docker run -d --name "$registry" -p "127.0.0.1:$port:5000" registry:2 >/dev/null
	started_registry=1
	for _ in $(seq 1 30); do
		curl -fsS "http://127.0.0.1:$port/v2/" >/dev/null 2>&1 && break
		sleep 1
	done
fi
ref="localhost:$port/falak-$engine:$version"
docker tag "$IMAGE" "$ref"
docker push -q "$ref" >/dev/null
digest=$(docker image inspect "$ref" | jq -r --arg r "localhost:$port/falak-$engine" '.[0].RepoDigests[] | select(startswith($r + "@")) | sub(".*@"; "")')
[ -n "$digest" ] || {
	echo "FAIL: no digest for $ref" >&2
	exit 1
}
echo "== $engine $version: $ref@$digest"

cd "$root/agent"
FALAK_PITR_ENGINE=$engine FALAK_PITR_VERSION=$version FALAK_PITR_IMAGE=$ref FALAK_PITR_DIGEST=$digest FALAK_PITR_WORK=$work \
	go test -tags pitre2e -count=1 -timeout 25m -run TestPITRFlowEndToEnd -v ./internal/db
echo "PASS $engine $version (point-in-time recovery)"
