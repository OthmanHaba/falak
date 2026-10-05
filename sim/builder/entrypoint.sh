#!/bin/sh
# Trust the sim edge CA (artifact upload/download goes through https://falak.test), then serve builds.
set -eu
until [ -s /falak/edge-pki/root.crt ]; do sleep 1; done
cp /falak/edge-pki/root.crt /usr/local/share/ca-certificates/falak-sim-edge.crt
update-ca-certificates >/dev/null

# Docker / compose builds: BuildKit in a container on the fleet network (reaches sim-registry over plain HTTP).
# falak-builder reuses an existing buildx builder named "falak".
if [ -S /var/run/docker.sock ] && docker version >/dev/null 2>&1; then
    mkdir -p /etc/buildkit
    cat > /etc/buildkit/buildkitd.toml <<TOML
[registry."${SIM_REGISTRY:-sim-registry:5000}"]
  http = true
  insecure = true
TOML
    # Base images (FROM docker.io/...) through the sim's Docker Hub pull-through cache.
    if [ -n "${SIM_HUB_MIRROR:-}" ]; then
        cat >> /etc/buildkit/buildkitd.toml <<TOML
[registry."docker.io"]
  mirrors = ["${SIM_HUB_MIRROR}"]
[registry."${SIM_HUB_MIRROR}"]
  http = true
  insecure = true
TOML
    fi
    # Recreate the builder (config may have changed) but keep its state volume: BuildKit's layer cache survives
    # builder restarts and `make reset` (`make clean-cache` removes it).
    docker buildx rm --keep-state falak >/dev/null 2>&1 || true
    docker buildx create --name falak --driver docker-container \
        --driver-opt "network=${SIM_BUILDKIT_NETWORK:-falak-sim_fleet}" \
        --buildkitd-config /etc/buildkit/buildkitd.toml --bootstrap >/dev/null \
        && echo "sim builder: buildx builder 'falak' on ${SIM_BUILDKIT_NETWORK:-falak-sim_fleet}" \
        || echo "sim builder: could not create the buildx builder; docker builds will fail" >&2
else
    echo "sim builder: no Docker socket; native builds only" >&2
fi

exec /opt/falak/falak-builder serve
