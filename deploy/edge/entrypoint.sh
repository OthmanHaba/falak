#!/bin/sh
# Kiln edge entrypoint (production counterpart of sim/edge/entrypoint.sh).
#
#  - renders the global TLS options (KILN_TLS=acme|internal, KILN_ACME_CA), the optional Grafana site and the
#    built-in registry site (KILN_REGISTRY_HOST, basic auth from KILN_REGISTRY_USERNAME/PASSWORD);
#  - keeps /etc/caddy/trust/ca.pem in sync with the Fleet CA written by the control plane
#    ($KILN_EDGE_CA_FILE, default /kiln/ca/ca.pem). Until it exists a throwaway placeholder CA (key
#    discarded) is trusted, so every non-enroll agent call fails closed;
#  - serves $KILN_AGENT_API_HOST with the Fleet-issued server certificate (agent-api.{pem,key}) once present;
#  - publishes Caddy's internal root to $KILN_EDGE_PKI_OUT (KILN_TLS=internal: the builder trusts it);
#  - hot-reloads Caddy whenever any of that changes.
set -eu

: "${KILN_DOMAIN:?KILN_DOMAIN is required}"
SRC="${KILN_EDGE_CA_FILE:-/kiln/ca/ca.pem}"
DST=/etc/caddy/trust/ca.pem
PKI_OUT="${KILN_EDGE_PKI_OUT:-}"
ROOT=/data/caddy/pki/authorities/local/root.crt
AGENT_HOST="${KILN_AGENT_API_HOST:-agents.$KILN_DOMAIN}"
AGENT_CERT="$(dirname "$SRC")/agent-api.pem"
AGENT_KEY="$(dirname "$SRC")/agent-api.key"
AGENT_TLS=/etc/caddy/agent-tls
SITE=/etc/caddy/sites/agent-api.caddyfile
export KILN_AGENT_API_HOST="$AGENT_HOST"
mkdir -p "$AGENT_TLS" /etc/caddy/sites /etc/caddy/global /etc/caddy/trust

# --- static render (once per container start) ---------------------------------------------------------
{
  case "${KILN_TLS:-acme}" in
    internal)
      echo "local_certs"
      echo "skip_install_trust"
      ;;
    acme)
      if [ -n "${KILN_ACME_CA:-}" ]; then echo "acme_ca ${KILN_ACME_CA}"; fi
      ;;
    *) echo "kiln-edge: KILN_TLS must be acme or internal" >&2; exit 2 ;;
  esac
} > /etc/caddy/global/tls.caddyfile

rm -f /etc/caddy/sites/grafana.caddyfile /etc/caddy/sites/aliases.caddyfile /etc/caddy/sites/registry.caddyfile
# Former panel domains (kiln-ctl domain set --keep-old) redirect to the current one.
if [ -n "${KILN_DOMAIN_ALIASES:-}" ]; then
  cat > /etc/caddy/sites/aliases.caddyfile <<SITE
$(printf '%s' "$KILN_DOMAIN_ALIASES" | sed 's/,/, /g') {
	redir ${KILN_URL:-https://$KILN_DOMAIN}{uri} permanent
}
SITE
fi
if [ -n "${KILN_GRAFANA_HOST:-}" ]; then
  cat > /etc/caddy/sites/grafana.caddyfile <<SITE
${KILN_GRAFANA_HOST} {
	import security_headers
	log
	reverse_proxy grafana:3000
}
SITE
fi

# Built-in image registry: never served without credentials (the registry itself has no auth). Former hosts
# (kiln-ctl domain set --keep-old) keep working, so images referenced by old releases still pull.
if [ -n "${KILN_REGISTRY_HOST:-}" ] && [ -n "${KILN_REGISTRY_USERNAME:-}" ] && [ -n "${KILN_REGISTRY_PASSWORD:-}" ]; then
  case "$KILN_REGISTRY_USERNAME" in *[!A-Za-z0-9_.-]*) echo "kiln-edge: KILN_REGISTRY_USERNAME may only use letters, digits, _ . -" >&2; exit 2 ;; esac
  REGISTRY_HASH="$(caddy hash-password --plaintext "$KILN_REGISTRY_PASSWORD")"
  cat > /etc/caddy/sites/registry.caddyfile <<SITE
${KILN_REGISTRY_HOST}$(printf '%s' "${KILN_REGISTRY_HOST_ALIASES:-}" | sed 's/^/,/; s/,/, /g; s/^, $//') {
	log
	header Docker-Distribution-Api-Version registry/2.0
	basic_auth {
		${KILN_REGISTRY_USERNAME} ${REGISTRY_HASH}
	}
	# Image layers: no request body limit, stream both ways.
	reverse_proxy registry:5000 {
		flush_interval -1
		transport http {
			read_timeout 30m
			write_timeout 30m
		}
	}
}
SITE
  unset REGISTRY_HASH
fi

# --- dynamic: Fleet CA + agent API certificate ---------------------------------------------------------
placeholder() {
  tmp=$(mktemp -d)
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 3650 \
    -subj "/CN=Kiln placeholder CA (no Fleet CA yet)" \
    -keyout "$tmp/key.pem" -out "$DST" >/dev/null 2>&1
  rm -rf "$tmp"
  echo "kiln-edge: Fleet CA not found at $SRC yet; using a placeholder (agent mTLS rejects everything)"
}

sync_ca() {
  if [ -s "$SRC" ] && openssl x509 -in "$SRC" -noout >/dev/null 2>&1; then
    if ! cmp -s "$SRC" "$DST"; then
      cp "$SRC" "$DST.tmp" && mv "$DST.tmp" "$DST"
      echo "kiln-edge: trusting Fleet CA $(openssl x509 -in "$DST" -noout -subject -fingerprint -sha256 | tr '\n' ' ')"
      return 0
    fi
    return 1
  fi
  [ -s "$DST" ] || { placeholder; return 0; }
  return 1
}

pem_normalise() {
  awk '{ gsub(/\r/, ""); gsub(/-----END ([A-Z ]+)-----/, "&\n"); gsub(/\n$/, ""); print }' | awk 'NF'
}

sync_agent_site() {
  if [ -s "$AGENT_CERT" ] && [ -s "$AGENT_KEY" ]; then
    if [ ! -f "$SITE" ] || ! cmp -s "$AGENT_CERT" "$AGENT_TLS/src.pem" || ! cmp -s "$AGENT_KEY" "$AGENT_TLS/src.key"; then
      cp "$AGENT_CERT" "$AGENT_TLS/src.pem" && cp "$AGENT_KEY" "$AGENT_TLS/src.key"
      pem_normalise < "$AGENT_CERT" > "$AGENT_TLS/agent-api.pem"
      pem_normalise < "$AGENT_KEY" > "$AGENT_TLS/agent-api.key"
      chmod 600 "$AGENT_TLS/agent-api.key"
      cat > "$SITE" <<SITE
$AGENT_HOST$(printf '%s' "${KILN_AGENT_API_HOST_ALIASES:-}" | sed 's/^/,/; s/,/, /g; s/^, $//') {
	tls $AGENT_TLS/agent-api.pem $AGENT_TLS/agent-api.key {
		import client_auth_fleet
	}
	log
	import kiln_routes
}
SITE
      echo "kiln-edge: serving the agent API on $AGENT_HOST with the Fleet-issued server certificate"
      return 0
    fi
    return 1
  fi
  if [ -f "$SITE" ]; then rm -f "$SITE"; return 0; fi
  return 1
}

publish_root() {
  if [ -n "$PKI_OUT" ] && [ -s "$ROOT" ] && [ -d "$PKI_OUT" ] && ! cmp -s "$ROOT" "$PKI_OUT/root.crt"; then
    cp "$ROOT" "$PKI_OUT/root.crt.tmp" && chmod 644 "$PKI_OUT/root.crt.tmp" && mv "$PKI_OUT/root.crt.tmp" "$PKI_OUT/root.crt"
    echo "kiln-edge: published the internal TLS root CA to $PKI_OUT/root.crt"
  fi
}

sync_ca || true
sync_agent_site || true

(
  while sleep "${KILN_EDGE_CA_POLL:-3}"; do
    publish_root
    changed=0
    sync_ca && changed=1
    sync_agent_site && changed=1
    if [ "$changed" = 1 ]; then
      if caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --force >/dev/null 2>&1; then
        echo "kiln-edge: reloaded (Fleet CA / agent API certificate changed)"
      else
        echo "kiln-edge: reload failed" >&2
      fi
    fi
  done
) &

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
