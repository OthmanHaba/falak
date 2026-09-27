# Kiln edge: Caddy terminating TLS for the panel (Let's Encrypt, or Caddy's internal CA with
# KILN_TLS=internal), serving the agent mTLS API with the Fleet-issued certificate, proxying Reverb
# websockets, and (optionally) Grafana and the OTLP ingress. Build context: deploy/edge.
FROM caddy:2.11.4-alpine
ARG KILN_VERSION=dev
LABEL org.opencontainers.image.title="kiln-edge" \
      org.opencontainers.image.description="Kiln edge (Caddy): ACME TLS, agent mTLS, Reverb websocket proxy" \
      org.opencontainers.image.version="${KILN_VERSION}"
RUN apk add --no-cache openssl curl
COPY Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0755 entrypoint.sh /usr/local/bin/kiln-edge-entrypoint
RUN mkdir -p /etc/caddy/trust /etc/caddy/sites /etc/caddy/global
HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=6 \
  CMD curl -fsS -o /dev/null http://localhost:2019/config/ || exit 1
ENTRYPOINT ["kiln-edge-entrypoint"]
