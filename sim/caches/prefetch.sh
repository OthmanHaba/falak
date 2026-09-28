#!/busybox/sh
# Sim cache warmer (compose service sim-prefetch): pulls the images the E2E's compose and template stages
# use through the sim's pull-through registry caches, in the background right after `make up`. On a cold
# cache the uncached pulls happen while the servers provision and the Laravel stages run, instead of inside
# the compose/template deployments. The servers still `docker compose pull` every image for real (from the
# cache). Warm caches make this a no-op. Idles afterwards so `make logs S=sim-prefetch` shows the result.
#   SIM_PREFETCH_TEMPLATES  template slugs whose images to warm (../templates/<slug>/compose.yaml)
#   SIM_PLATFORM            linux/<arch> of the sim servers
set -u
platform=${SIM_PLATFORM:-linux/arm64}

images() {
    for slug in ${SIM_PREFETCH_TEMPLATES:-}; do
        sed -n 's/^[[:space:]]*image:[[:space:]]*\([^[:space:]#]*\).*/\1/p' "/templates/$slug/compose.yaml"
    done
    for f in /fixtures/apps/*/compose.yaml; do
        sed -n 's/^[[:space:]]*image:[[:space:]]*\([^[:space:]#$]*\).*/\1/p' "$f"
    done
    for f in /fixtures/apps/*/*/Dockerfile /fixtures/apps/*/Dockerfile; do
        [ -f "$f" ] && sed -n 's/^FROM[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$f"
    done
}

# image reference -> the same image on the sim cache that serves its registry (empty: not cached)
mirror_ref() {
    ref=$1
    case $ref in
        ghcr.io/*) echo "sim-ghcr-mirror:5000/${ref#ghcr.io/}" ;;
        docker.io/*) mirror_ref "${ref#docker.io/}" ;;
        *.*/* | *:*/* | localhost/*) echo "" ;;      # another registry (quay.io/…, host:port/…): not cached
        */*) echo "sim-hub-mirror:5000/$ref" ;;
        *) echo "sim-hub-mirror:5000/library/$ref" ;;
    esac
}

start=$(date +%s)
for ref in $(images | sort -u); do
    m=$(mirror_ref "$ref")
    if [ -z "$m" ]; then echo "prefetch: skip $ref (no sim cache for its registry)"; continue; fi
    t0=$(date +%s)
    if crane pull --insecure --platform "$platform" "$m" /dev/null >/dev/null 2>/tmp/err; then
        echo "prefetch: $ref ($(( $(date +%s) - t0 ))s)"
    else
        echo "prefetch: FAILED $ref: $(tail -1 /tmp/err)"
    fi
done
echo "prefetch: done in $(( $(date +%s) - start ))s"
while :; do sleep 3600; done
