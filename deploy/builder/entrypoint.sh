#!/bin/sh
# Kiln builder entrypoint. With KILN_TLS=internal (local testing) the edge serves the panel with Caddy's
# internal CA: wait for the edge to publish its root and trust it. Then run kiln-builder.
set -eu
if [ "${KILN_TLS:-acme}" = "internal" ]; then
  root="${KILN_EDGE_ROOT_CA:-/kiln/edge-pki/root.crt}"
  i=0
  until [ -s "$root" ]; do
    i=$((i + 1)); [ "$i" -gt 120 ] && { echo "kiln-builder: $root never appeared" >&2; exit 1; }
    sleep 1
  done
  cp "$root" /usr/local/share/ca-certificates/kiln-edge-internal.crt
  update-ca-certificates >/dev/null 2>&1
fi
exec kiln-builder "$@"
