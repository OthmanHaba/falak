#!/bin/sh
# Trust the sim edge CA (artifact upload/download goes through https://kiln.test), then serve builds.
set -eu
until [ -s /kiln/edge-pki/root.crt ]; do sleep 1; done
cp /kiln/edge-pki/root.crt /usr/local/share/ca-certificates/kiln-sim-edge.crt
update-ca-certificates >/dev/null
exec /opt/kiln/kiln-builder serve
