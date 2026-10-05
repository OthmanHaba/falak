#!/bin/sh
# Keeps /etc/caddy/trust/ca.pem in sync with the Fleet CA written by the control plane and
# hot-reloads Caddy when it changes. Until the Fleet CA exists, a throwaway placeholder CA
# (private key discarded) is used, so every /agent/v1/* call except enroll fails closed (401).
# Also publishes Caddy's internal root (the edge's TLS server CA) for the sim servers.
set -eu

SRC="${FALAK_EDGE_CA_FILE:-/falak/ca/ca.pem}"
DST=/etc/caddy/trust/ca.pem
PKI_OUT="${FALAK_EDGE_PKI_OUT:-/falak/edge-pki}"
ROOT=/data/caddy/pki/authorities/local/root.crt
AGENT_HOST="${FALAK_AGENT_API_HOST:-agents.falak.test}"
AGENT_CERT="$(dirname "$SRC")/agent-api.pem"
AGENT_KEY="$(dirname "$SRC")/agent-api.key"
AGENT_TLS=/etc/caddy/agent-tls
SITE=/etc/caddy/sites/agent-api.caddyfile
mkdir -p "$AGENT_TLS" /etc/caddy/sites

placeholder() {
  tmp=$(mktemp -d)
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 3650 \
    -subj "/CN=Falak sim placeholder CA (no Fleet CA yet)" \
    -keyout "$tmp/key.pem" -out "$DST" >/dev/null 2>&1
  rm -rf "$tmp"
  echo "falak-edge: Fleet CA not found at $SRC; using placeholder (agent mTLS will reject everything)"
}

sync_ca() {
  if [ -s "$SRC" ] && openssl x509 -in "$SRC" -noout >/dev/null 2>&1; then
    if ! cmp -s "$SRC" "$DST"; then
      cp "$SRC" "$DST.tmp" && mv "$DST.tmp" "$DST"
      echo "falak-edge: trusting Fleet CA $(openssl x509 -in "$DST" -noout -subject -fingerprint -sha256 | tr '\n' ' ')"
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

# Agent API site: served with the Fleet-issued server cert (agents pin the Falak CA).
sync_agent_site() {
  if [ -s "$AGENT_CERT" ] && [ -s "$AGENT_KEY" ]; then
    if [ ! -f "$SITE" ] || ! cmp -s "$AGENT_CERT" "$AGENT_TLS/src.pem" || ! cmp -s "$AGENT_KEY" "$AGENT_TLS/src.key"; then
      cp "$AGENT_CERT" "$AGENT_TLS/src.pem" && cp "$AGENT_KEY" "$AGENT_TLS/src.key"
      # Normalise PEM (CRLF, and chains concatenated without a newline between blocks).
      pem_normalise < "$AGENT_CERT" > "$AGENT_TLS/agent-api.pem"
      pem_normalise < "$AGENT_KEY" > "$AGENT_TLS/agent-api.key"
      chmod 600 "$AGENT_TLS/agent-api.key"
      cat > "$SITE" <<SITE
$AGENT_HOST {
	tls $AGENT_TLS/agent-api.pem $AGENT_TLS/agent-api.key {
		import client_auth_fleet
	}
	import falak_routes
}
SITE
      echo "falak-edge: serving agent API on $AGENT_HOST with the Fleet-issued server certificate"
      return 0
    fi
    return 1
  fi
  if [ -f "$SITE" ]; then rm -f "$SITE"; return 0; fi
  return 1
}

publish_root() {
  if [ -s "$ROOT" ] && [ -d "$PKI_OUT" ] && ! cmp -s "$ROOT" "$PKI_OUT/root.crt"; then
    cp "$ROOT" "$PKI_OUT/root.crt.tmp" && chmod 644 "$PKI_OUT/root.crt.tmp" && mv "$PKI_OUT/root.crt.tmp" "$PKI_OUT/root.crt"
    echo "falak-edge: published edge TLS root CA to $PKI_OUT/root.crt"
  fi
}

sync_ca || true
sync_agent_site || true

(
  while sleep "${FALAK_EDGE_CA_POLL:-3}"; do
    publish_root
    changed=0
    sync_ca && changed=1
    sync_agent_site && changed=1
    if [ "$changed" = 1 ]; then
      caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --force >/dev/null 2>&1 \
        && echo "falak-edge: reloaded (client CA / agent API certificate changed)" \
        || echo "falak-edge: reload failed" >&2
    fi
  done
) &

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
