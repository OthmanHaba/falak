#!/bin/sh
# Trust the sim edge CA (artifact upload/download goes through https://kiln.test), then serve builds.
set -eu
until [ -s /kiln/edge-pki/root.crt ]; do sleep 1; done
cp /kiln/edge-pki/root.crt /usr/local/share/ca-certificates/kiln-sim-edge.crt
update-ca-certificates >/dev/null

# Docker / compose builds: BuildKit in a container on the fleet network (reaches sim-registry over plain HTTP).
# kiln-builder reuses an existing buildx builder named "kiln".
if [ -S /var/run/docker.sock ] && docker version >/dev/null 2>&1; then
    mkdir -p /etc/buildkit
    cat > /etc/buildkit/buildkitd.toml <<TOML
[registry."${SIM_REGISTRY:-sim-registry:5000}"]
  http = true
  insecure = true
TOML
    docker buildx rm kiln >/dev/null 2>&1 || true
    docker buildx create --name kiln --driver docker-container \
        --driver-opt "network=${SIM_BUILDKIT_NETWORK:-kiln-sim_fleet}" \
        --buildkitd-config /etc/buildkit/buildkitd.toml --bootstrap >/dev/null \
        && echo "sim builder: buildx builder 'kiln' on ${SIM_BUILDKIT_NETWORK:-kiln-sim_fleet}" \
        || echo "sim builder: could not create the buildx builder; docker builds will fail" >&2
else
    echo "sim builder: no Docker socket; native builds only" >&2
fi

exec /opt/kiln/kiln-builder serve
