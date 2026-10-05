# Sim edge: Caddy terminating TLS for the control plane (Caddy internal CA) and verifying
# agent client certificates against the Falak Fleet CA (read from /falak/ca/ca.pem).
FROM caddy:2.11.4-alpine
RUN apk add --no-cache openssl curl
COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/falak-edge-entrypoint
RUN chmod +x /usr/local/bin/falak-edge-entrypoint && mkdir -p /etc/caddy/trust
ENTRYPOINT ["falak-edge-entrypoint"]
