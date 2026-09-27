#!/usr/bin/env bash
# Assemble GitHub release assets (used by .github/workflows/release.yml; runnable locally).
#   deploy/tools/release-assets.sh <tag> <owner/repo> <out-dir>
# Expects agent/bin/ from `make -C agent build VERSION=<tag>`. Produces:
#   kiln-agent-linux-{amd64,arm64}, kiln-builder-linux-{amd64,arm64}, kiln-{linux,darwin}-{amd64,arm64},
#   kiln-deploy.tar.gz (deploy/ + observability/, install.sh/kiln-ctl pinned to <owner/repo>),
#   install.sh (defaults to <tag>), kiln-ctl, SHA256SUMS
set -euo pipefail
tag="${1:?tag}"; repo="${2:?owner/repo}"; out="${3:?out dir}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
bin="$root/agent/bin"
[ -x "$bin/kiln-agent-linux-amd64" ] || { echo "missing $bin (run: make -C agent build VERSION=$tag)" >&2; exit 1; }

rm -rf "$out"; mkdir -p "$out"
out="$(cd "$out" && pwd)"
for f in kiln-agent-linux-amd64 kiln-agent-linux-arm64 kiln-builder-linux-amd64 kiln-builder-linux-arm64 \
         kiln-linux-amd64 kiln-linux-arm64 kiln-darwin-amd64 kiln-darwin-arm64; do
  cp "$bin/$f" "$out/$f"
done

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
cp -R "$root/deploy" "$stage/deploy"
mkdir -p "$stage/observability"
for d in gateway grafana loki mimir tempo; do cp -R "$root/observability/$d" "$stage/observability/$d"; done
cp "$root/observability/compose.yml" "$root/observability/README.md" "$stage/observability/"
find "$stage" -name .DS_Store -delete

pin() { # pin FILE : default repo -> $repo; install.sh defaults to this release
  local tmp="$1.tmp"
  sed -e "s#^DEFAULT_REPO=\".*\"#DEFAULT_REPO=\"$repo\"#" \
      -e "s#^KILN_REPO_DEFAULT=\".*\"#KILN_REPO_DEFAULT=\"$repo\"#" \
      -e "s#^VERSION=\"\${KILN_VERSION:-}\"#VERSION=\"\${KILN_VERSION:-$tag}\"#" "$1" > "$tmp"
  chmod --reference="$1" "$tmp" 2>/dev/null || chmod 755 "$tmp"
  mv "$tmp" "$1"
}
pin "$stage/deploy/kiln-ctl"
pin "$stage/deploy/install.sh"
cp "$stage/deploy/install.sh" "$out/install.sh"
cp "$stage/deploy/kiln-ctl" "$out/kiln-ctl"

if tar --version 2>/dev/null | grep -q GNU; then
  tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@${SOURCE_DATE_EPOCH:-0}" \
    -C "$stage" -czf "$out/kiln-deploy.tar.gz" deploy observability
else
  tar -C "$stage" -czf "$out/kiln-deploy.tar.gz" deploy observability
fi

cd "$out"
if command -v sha256sum >/dev/null 2>&1; then sha256sum -- * > SHA256SUMS; else shasum -a 256 -- * > SHA256SUMS; fi
cd - >/dev/null
echo "release assets for $tag in $out:"
(cd "$out" && ls -l)
