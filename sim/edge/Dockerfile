# Sim edge: Caddy terminating TLS for the control plane (Caddy internal CA) and verifying
# agent client certificates against the Kiln Fleet CA (read from /kiln/ca/ca.pem).
FROM caddy:2.11.4-alpine
RUN apk add --no-cache openssl curl
COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/kiln-edge-entrypoint
RUN chmod +x /usr/local/bin/kiln-edge-entrypoint && mkdir -p /etc/caddy/trust
ENTRYPOINT ["kiln-edge-entrypoint"]
