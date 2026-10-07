#!/usr/bin/env bash
# Build one Falak database image from images/db/versions.json and load it into the local Docker.
#
#   images/db/build.sh <engine> <version> [docker buildx build flags...]
#   images/db/build.sh postgres 17                         # -> falak-postgres:17-local
#   images/db/build.sh mysql 8.4 --platform linux/amd64
#   images/db/build.sh --args mysql 8.4                    # print the --build-arg flags only (CI)
#
# Environment: IMAGE_TAG (default falak-<engine>:<version>-local), FALAK_VERSION (default dev).
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
versions="$here/versions.json"

args_only=
if [ "${1:-}" = --args ]; then
	args_only=1
	shift
fi
engine=${1:?engine}
version=${2:?version}
shift 2

entry=$(jq -ce --arg e "$engine" --arg v "$version" '.images[] | select(.engine == $e and .version == $v)' "$versions") || {
	echo "build.sh: $engine $version is not in versions.json" >&2
	exit 2
}

build_args=(
	--build-arg "GO_IMAGE=$(jq -r .go_image "$versions")"
	--build-arg "BASE_IMAGE=$(jq -r .base <<<"$entry")"
	--build-arg "ENGINE_VERSION=$version"
	--build-arg "FALAK_VERSION=${FALAK_VERSION:-dev}"
)
while IFS= read -r kv; do
	[ -n "$kv" ] && build_args+=(--build-arg "$kv")
done < <(jq -r '(.args // {}) | to_entries[] | "\(.key)=\(.value)"' <<<"$entry")

if [ -n "$args_only" ]; then
	printf '%s\n' "${build_args[@]}"
	exit 0
fi

tag=${IMAGE_TAG:-falak-$engine:$version-local}
docker buildx build \
	-f "$here/$engine/Dockerfile" \
	"${build_args[@]}" \
	--label "org.opencontainers.image.revision=$(git -C "$root" rev-parse HEAD 2>/dev/null || echo unknown)" \
	--load -t "$tag" "$@" "$root"
echo "$tag"
