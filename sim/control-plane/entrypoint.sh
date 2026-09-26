#!/bin/sh
# Roles: web (default) | horizon | reverb | scheduler | <any command>
set -eu
cd /app

mkdir -p "${KILN_CA_PATH:-/kiln/ca}" storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

cache_config() {
  php artisan config:cache --no-interaction >/dev/null
  php artisan route:cache --no-interaction >/dev/null 2>&1 || echo "kiln: route:cache failed (closure routes?), continuing uncached" >&2
  php artisan view:cache --no-interaction >/dev/null 2>&1 || true
}

# Sim: issue the agent-API server certificate from the Kiln CA (agents pin that CA) so the
# edge can serve KILN_EDGE_AGENT_API_HOST. Also creates the CA and writes $KILN_CA_PATH/ca.pem.
ensure_agent_api_cert() {
  host="${KILN_EDGE_AGENT_API_HOST:-}"
  [ -n "$host" ] || return 0
  ca_dir="${KILN_CA_PATH:-/kiln/ca}"
  if ! php artisan list --raw 2>/dev/null | grep -q '^fleet:ca:server-cert'; then
    echo "kiln: fleet:ca:server-cert not available; skipping agent API certificate" >&2
    return 0
  fi
  if [ -s "$ca_dir/agent-api.pem" ] && [ -s "$ca_dir/ca.pem" ] \
     && openssl x509 -in "$ca_dir/agent-api.pem" -noout -checkend 2592000 >/dev/null 2>&1 \
     && openssl verify -CAfile "$ca_dir/ca.pem" "$ca_dir/agent-api.pem" >/dev/null 2>&1; then
    return 0
  fi
  echo "kiln: issuing agent API server certificate for $host"
  php artisan fleet:ca:server-cert "$host" --out "$ca_dir" --no-interaction || echo "kiln: agent API certificate issuance failed" >&2
}

role="${1:-web}"
case "$role" in
  web)
    cache_config
    echo "kiln: running migrations"
    php artisan migrate --force --no-interaction
    ensure_agent_api_cert
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
    ;;
  horizon)
    cache_config
    exec php artisan horizon
    ;;
  reverb)
    cache_config
    exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}"
    ;;
  scheduler)
    cache_config
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
