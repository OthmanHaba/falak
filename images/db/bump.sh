#!/usr/bin/env bash
# Re-resolve every base image tag in images/db/versions.json (and the Go builder image) to its current digest.
#
#   images/db/bump.sh            # rewrite versions.json, print what changed
#   images/db/bump.sh --check    # exit 1 if a digest is out of date (nothing written)
#
# XtraBackup is pinned separately (args.XB_VERSION and the two SHA-256 sums): see docs/DB_IMAGES.md.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
versions="$here/versions.json"
check=
[ "${1:-}" = --check ] && check=1

digest_of() {
	docker buildx imagetools inspect "$1" --format '{{json .Manifest}}' | jq -r .digest
}

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
cp "$versions" "$tmp"
changed=0

bump() { # bump <jq path to the ref>
	local path=$1 ref tag digest
	ref=$(jq -r "$path" "$tmp")
	tag=${ref%@*}
	digest=$(digest_of "$tag")
	[ -n "$digest" ] && [ "$digest" != null ] || {
		echo "bump.sh: cannot resolve $tag" >&2
		exit 1
	}
	if [ "$ref" != "$tag@$digest" ]; then
		echo "$tag: ${ref#*@} -> $digest"
		changed=1
		jq --indent 2 "$path = \"$tag@$digest\"" "$tmp" >"$tmp.new" && mv "$tmp.new" "$tmp"
	fi
}

bump .go_image
for i in $(seq 0 $(($(jq '.images | length' "$tmp") - 1))); do
	bump ".images[$i].base"
done

if [ "$changed" = 0 ]; then
	echo "all digests are current"
	exit 0
fi
[ -n "$check" ] && exit 1
cp "$tmp" "$versions"
echo "updated $versions: rebuild and run images/db/test.sh before committing"
