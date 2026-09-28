# Sim apt cache (apt-cacher-ng): the sim servers use it as their HTTP apt proxy when it is reachable
# (Proxy-Auto-Detect, see server/bin/kiln-sim-apt-proxy). apt still resolves and installs every package on
# the server; only the .deb / index downloads are served from the cache after the first run.
FROM ubuntu:noble-20260911
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends apt-cacher-ng ca-certificates curl \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /run/apt-cacher-ng && chown apt-cacher-ng:apt-cacher-ng /run/apt-cacher-ng /var/cache/apt-cacher-ng /var/log/apt-cacher-ng
USER apt-cacher-ng
EXPOSE 3142
CMD ["/usr/sbin/apt-cacher-ng", "-c", "/etc/apt-cacher-ng", "ForeGround=1", "Port=3142", "BindAddress=0.0.0.0", "ExThreshold=365"]
