#!/bin/sh
# Falak builder entrypoint. With FALAK_TLS=internal (local testing) the edge serves the panel with Caddy's
# internal CA: wait for the edge to publish its root and trust it. Then run falak-builder.
set -eu
if [ "${FALAK_TLS:-acme}" = "internal" ]; then
  root="${FALAK_EDGE_ROOT_CA:-/falak/edge-pki/root.crt}"
  i=0
  until [ -s "$root" ]; do
    i=$((i + 1)); [ "$i" -gt 120 ] && { echo "falak-builder: $root never appeared" >&2; exit 1; }
    sleep 1
  done
  cp "$root" /usr/local/share/ca-certificates/falak-edge-internal.crt
  update-ca-certificates >/dev/null 2>&1
fi
exec falak-builder "$@"
