#!/bin/sh
# Kiln control-plane entrypoint.
#   roles: web (default) | horizon | reverb | scheduler | <any command, e.g. php artisan ...>
#
#   KILN_MIGRATE=1          web role runs `migrate --force` before serving (set on exactly one service)
#   KILN_WAIT_TIMEOUT=120   seconds to wait for Postgres/Valkey before giving up
#   KILN_EDGE_AGENT_API_HOST  issue/renew the agent-API server certificate from the Fleet CA into
#                           $KILN_CA_PATH (agent-api.pem/.key) — the edge serves the agents host with it.
set -eu
cd /app

role="${1:-web}"
ca_dir="${KILN_CA_PATH:-/kiln/ca}"
mkdir -p "$ca_dir" storage/app/public storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs storage/kiln 2>/dev/null || true

log() { echo "kiln: $*" >&2; }

wait_for_services() {
  timeout="${KILN_WAIT_TIMEOUT:-120}"
  # shellcheck disable=SC2016  # PHP code
  php -r '
    $deadline = time() + (int) $argv[1];
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "postgres", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "kiln");
    $pg = $redis = false;
    while (time() < $deadline) {
        if (! $pg) {
            try { new PDO($dsn, getenv("DB_USERNAME") ?: "kiln", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 3]); $pg = true; }
            catch (Throwable $e) { $err = $e->getMessage(); }
        }
        if (! $redis) {
            $redis = (bool) @fsockopen(getenv("REDIS_HOST") ?: "valkey", (int) (getenv("REDIS_PORT") ?: 6379), $n, $s, 3);
        }
        if ($pg && $redis) { exit(0); }
        sleep(2);
    }
    fwrite(STDERR, "kiln: timed out waiting for ".(! $pg ? "Postgres (".($err ?? "?").")" : "Valkey")."\n");
    exit(1);
  ' "$timeout"
}

cache_config() {
  php artisan config:cache --no-interaction >/dev/null
  php artisan route:cache --no-interaction >/dev/null 2>&1 || log "route:cache failed (closure routes?), continuing uncached"
  php artisan view:cache --no-interaction >/dev/null 2>&1 || true
  php artisan event:cache --no-interaction >/dev/null 2>&1 || true
}

# Agents pin the Kiln CA on the mTLS API, so the agents host is served with a certificate issued by the
# Fleet CA (created on first use, key encrypted with APP_KEY in the database). Renewed 30 days before
# expiry or when it no longer chains to the current CA; the edge hot-reloads when the files change.
ensure_agent_api_cert() {
  host="${KILN_EDGE_AGENT_API_HOST:-}"
  [ -n "$host" ] || return 0
  # Former agent API names (after `kiln-ctl domain set`) stay on the certificate: enrolled agents keep them.
  hosts="$host $(printf '%s' "${KILN_AGENT_API_HOST_ALIASES:-}" | tr ',' ' ')"
  if [ -s "$ca_dir/agent-api.pem" ] && [ -s "$ca_dir/ca.pem" ] \
     && openssl x509 -in "$ca_dir/agent-api.pem" -noout -checkend 2592000 >/dev/null 2>&1 \
     && openssl verify -CAfile "$ca_dir/ca.pem" "$ca_dir/agent-api.pem" >/dev/null 2>&1; then
    sans="$(openssl x509 -in "$ca_dir/agent-api.pem" -noout -ext subjectAltName 2>/dev/null | tr ',' '\n' | sed -n 's/^ *DNS://p')"
    missing=0
    for h in $hosts; do
      printf '%s\n' "$sans" | grep -qx "$h" || missing=1
    done
    [ "$missing" = 0 ] && return 0
  fi
  log "issuing agent API server certificate for $hosts"
  # shellcheck disable=SC2086
  php artisan fleet:ca:server-cert $hosts --out "$ca_dir" --no-interaction >/dev/null \
    || log "agent API certificate issuance failed (agents cannot connect until this succeeds)"
}

echo "$role" > /tmp/kiln-role 2>/dev/null || true

case "$role" in
  web)
    wait_for_services
    cache_config
    if [ "${KILN_MIGRATE:-0}" = "1" ]; then
      log "running migrations"
      php artisan migrate --force --no-interaction
    fi
    ensure_agent_api_cert
    # Renew the agent API certificate daily while running (cheap no-op when still valid).
    ( while sleep 86400; do ensure_agent_api_cert; done ) &
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
    ;;
  horizon)
    wait_for_services
    cache_config
    exec php artisan horizon
    ;;
  reverb)
    wait_for_services
    cache_config
    exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}"
    ;;
  scheduler)
    wait_for_services
    cache_config
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
