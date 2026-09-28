#!/usr/bin/env bash
# Kiln installer — single-host Docker Compose install into /opt/kiln.
#
#   curl -fsSL https://raw.githubusercontent.com/OthmanHaba/kiln/main/deploy/install.sh \
#     | sudo bash -s -- --domain kiln.example.com --email you@example.com
#
# Options (each also settable via the environment variable in brackets):
#   --domain NAME            panel domain; agents use agents.NAME                           [KILN_DOMAIN]
#   --email ADDRESS          Let's Encrypt account + first admin e-mail                      [KILN_EMAIL]
#   --admin-email ADDRESS    first admin (default: --email)                                  [KILN_ADMIN_EMAIL]
#   --version TAG            release to install (default: latest release)                    [KILN_VERSION]
#   --observability          also run Grafana/Loki/Tempo/VictoriaMetrics (grafana.NAME)      [KILN_OBSERVABILITY=1]
#   --tls acme|internal      internal = Caddy's local CA, for testing only (default: acme)    [KILN_TLS]
#   --repo OWNER/NAME        GitHub repository of the release (default: OthmanHaba/kiln)         [KILN_REPO]
#   --image-prefix PREFIX    image registry prefix (default: ghcr.io/<owner>)                [KILN_IMAGE_PREFIX]
#   --build-from-source      build images locally from --ref instead of pulling them         [KILN_BUILD_FROM_SOURCE=1]
#   --ref REF                git ref for --build-from-source (default: --version or main)    [KILN_REF]
#   --source-dir PATH        use deploy files from a local checkout (offline/testing)       [KILN_DEPLOY_SOURCE]
#   --http-port N / --https-port N   non-standard ports (testing; ACME needs 80/443)        [KILN_HTTP_PORT/KILN_HTTPS_PORT]
#   --dir PATH               install directory (default: /opt/kiln)                          [KILN_DIR]
#   --skip-dns-check         don't require DNS to point at this host                        [KILN_SKIP_DNS_CHECK=1]
#   --force                  continue on unsupported OS / low resources
#
# Re-running is safe: secrets in /opt/kiln/.env are kept, settings given on the command line are updated,
# the stack is converged, and the first admin is only created once.
set -euo pipefail

KILN_DIR="${KILN_DIR:-/opt/kiln}"
KILN_PROJECT="${KILN_PROJECT:-kiln}"
DOMAIN="${KILN_DOMAIN:-}"
EMAIL="${KILN_EMAIL:-}"
ADMIN_EMAIL="${KILN_ADMIN_EMAIL:-}"
VERSION="${KILN_VERSION:-}"
OBSERVABILITY="${KILN_OBSERVABILITY:-}"
TLS="${KILN_TLS:-}"
REPO="${KILN_REPO:-}"
IMAGE_PREFIX="${KILN_IMAGE_PREFIX:-}"
FROM_SOURCE="${KILN_BUILD_FROM_SOURCE:-0}"
REF="${KILN_REF:-}"
SOURCE_DIR="${KILN_DEPLOY_SOURCE:-}"
HTTP_PORT="${KILN_HTTP_PORT:-}"
HTTPS_PORT="${KILN_HTTPS_PORT:-}"
SKIP_DNS="${KILN_SKIP_DNS_CHECK:-0}"
FORCE=0
DEFAULT_REPO="OthmanHaba/kiln"

if [ -t 1 ]; then B=$'\e[1m'; R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; N=$'\e[0m'; else B=; R=; G=; Y=; N=; fi
info() { printf '%s==>%s %s\n' "$B" "$N" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*" >&2; }
die()  { printf '\n%serror:%s %s\n' "$R" "$N" "$*" >&2; exit 1; }

force_or_die() { if [ "$FORCE" = 1 ]; then warn "$1 (continuing: --force)"; else die "$2"; fi; }
os_field() { sed -n "s/^$1=//p" /etc/os-release 2>/dev/null | tr -d '"' | head -1; }

usage() { sed -n '2,28p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//' || echo "see docs/INSTALL.md"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift ;;
    --email) EMAIL="$2"; shift ;;
    --admin-email) ADMIN_EMAIL="$2"; shift ;;
    --version) VERSION="$2"; shift ;;
    --observability) OBSERVABILITY=1 ;;
    --no-observability) OBSERVABILITY=0 ;;
    --tls) TLS="$2"; shift ;;
    --repo) REPO="$2"; shift ;;
    --image-prefix) IMAGE_PREFIX="$2"; shift ;;
    --build-from-source) FROM_SOURCE=1 ;;
    --ref) REF="$2"; shift ;;
    --source-dir) SOURCE_DIR="$2"; shift ;;
    --http-port) HTTP_PORT="$2"; shift ;;
    --https-port) HTTPS_PORT="$2"; shift ;;
    --dir) KILN_DIR="$2"; shift ;;
    --skip-dns-check) SKIP_DNS=1 ;;
    --force) FORCE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
  shift
done

ENV_FILE="$KILN_DIR/.env"

# --- .env helpers (same format as kiln-ctl) -------------------------------------------------------------
env_get() {
  local v
  v="$(grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- || true)"
  printf '%s' "${v:-${2:-}}"
}
env_set() {
  local tmp
  tmp="$(mktemp "$KILN_DIR/.env.XXXXXX")"
  chmod 600 "$tmp"
  if grep -qE "^$1=" "$ENV_FILE" 2>/dev/null; then
    awk -v k="$1" -v v="$2" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
  else
    { cat "$ENV_FILE" 2>/dev/null || true; printf '%s=%s\n' "$1" "$2"; } > "$tmp"
  fi
  mv "$tmp" "$ENV_FILE"
}
env_default() { [ -n "$(env_get "$1")" ] || env_set "$1" "$2"; }   # set only when missing (secrets)
rand_hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }

# Reuse settings of an existing install when flags are omitted (idempotent re-runs, upgrades of flags).
if [ -f "$ENV_FILE" ]; then
  DOMAIN="${DOMAIN:-$(env_get KILN_DOMAIN)}"
  EMAIL="${EMAIL:-$(env_get KILN_ACME_EMAIL)}"
  TLS="${TLS:-$(env_get KILN_TLS)}"
  REPO="${REPO:-$(env_get KILN_REPO)}"
  IMAGE_PREFIX="${IMAGE_PREFIX:-$(env_get KILN_IMAGE_PREFIX)}"
  VERSION="${VERSION:-$(env_get KILN_VERSION)}"
  HTTP_PORT="${HTTP_PORT:-$(env_get KILN_HTTP_PORT)}"
  HTTPS_PORT="${HTTPS_PORT:-$(env_get KILN_HTTPS_PORT)}"
  SOURCE_DIR="${SOURCE_DIR:-$(env_get KILN_DEPLOY_SOURCE)}"
  if [ -z "$OBSERVABILITY" ]; then
    case "$(env_get COMPOSE_PROFILES)" in *observability*) OBSERVABILITY=1 ;; esac
  fi
fi
TLS="${TLS:-acme}"
REPO="${REPO:-$DEFAULT_REPO}"
HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
OBSERVABILITY="${OBSERVABILITY:-0}"
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL}"
OWNER_LC="$(printf '%s' "${REPO%%/*}" | tr '[:upper:]' '[:lower:]')"
IMAGE_PREFIX="${IMAGE_PREFIX:-ghcr.io/$OWNER_LC}"
# Source builds are tagged locally (never pushed) unless a prefix was given explicitly.
if [ "$FROM_SOURCE" = 1 ] && [ -z "${KILN_IMAGE_PREFIX:-}" ] && [ "$IMAGE_PREFIX" = "ghcr.io/$OWNER_LC" ]; then
  IMAGE_PREFIX="kiln-local"
fi

# --- validation -----------------------------------------------------------------------------------------
[ -n "$DOMAIN" ] || die "--domain is required (e.g. --domain kiln.example.com)"
printf '%s' "$DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$' \
  || die "--domain '$DOMAIN' is not a valid lower-case DNS name"
case "$TLS" in acme|internal) ;; *) die "--tls must be acme or internal" ;; esac
if [ "$TLS" = acme ]; then
  [ -n "$EMAIL" ] || die "--email is required for Let's Encrypt (--tls acme)"
  { [ "$HTTP_PORT" = 80 ] && [ "$HTTPS_PORT" = 443 ]; } || die "Let's Encrypt needs ports 80/443 (use --tls internal for custom ports)"
fi
[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="admin@$DOMAIN"
printf '%s' "$ADMIN_EMAIL" | grep -Eq '^[^@ ]+@[^@ ]+\.[^@ ]+$' || die "invalid e-mail: $ADMIN_EMAIL"
AGENTS_HOST="agents.$DOMAIN"
GRAFANA_HOST=""; [ "$OBSERVABILITY" = 1 ] && GRAFANA_HOST="grafana.$DOMAIN"
PORT_SUFFIX=""; [ "$HTTPS_PORT" != 443 ] && PORT_SUFFIX=":$HTTPS_PORT"

# --- preflight ------------------------------------------------------------------------------------------
preflight() {
  info "Preflight checks"
  [ "$(id -u)" = 0 ] || die "run as root (curl ... | sudo bash -s -- ...)"

  local id ver
  id="$(os_field ID)"; ver="$(os_field VERSION_ID)"
  case "$id:$ver" in
    ubuntu:22.04|ubuntu:24.04|debian:12) ok "OS: $id $ver" ;;
    *) force_or_die "unsupported OS '$id $ver'" \
         "unsupported OS '$id $ver': Kiln supports Ubuntu 22.04/24.04 and Debian 12 (--force to try anyway)" ;;
  esac

  local arch; arch="$(uname -m)"
  case "$arch" in x86_64|aarch64) ok "arch: $arch" ;; *) die "unsupported CPU architecture $arch (amd64/arm64 only)" ;; esac

  local mem_mb
  mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
  if [ "$mem_mb" -lt 1900 ]; then
    force_or_die "only ${mem_mb} MB RAM" "Kiln needs at least 2 GB RAM (found ${mem_mb} MB; --force to try anyway)"
  elif [ "$mem_mb" -lt 3800 ]; then warn "${mem_mb} MB RAM: works, but 4 GB is recommended (builds are memory-hungry)"
  else ok "RAM: ${mem_mb} MB"; fi
  if [ "$OBSERVABILITY" = 1 ] && [ "$mem_mb" -lt 7600 ]; then
    warn "--observability on ${mem_mb} MB RAM is tight (8 GB recommended); consider a separate observability host"
  fi

  mkdir -p "$KILN_DIR"
  local free_gb
  free_gb="$(df -Pk "$KILN_DIR" | awk 'NR==2 {print int($4/1024/1024)}')"
  if [ "$free_gb" -lt 10 ]; then
    force_or_die "only ${free_gb} GB free disk" "at least 10 GB free disk needed on $KILN_DIR (found ${free_gb} GB; --force to try anyway)"
  elif [ "$free_gb" -lt 25 ]; then warn "${free_gb} GB free disk (25+ GB recommended for artifacts, backups and images)"
  else ok "disk: ${free_gb} GB free"; fi

  local p holder
  for p in "$HTTP_PORT" "$HTTPS_PORT"; do
    holder="$(ss -Hltnp "sport = :$p" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -1 || true)"
    if [ -z "$holder" ]; then ok "port $p free"
    elif docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${KILN_PROJECT}-edge-"; then ok "port $p in use by Kiln (re-run)"
    else die "port $p is in use by '$holder'. Stop it (e.g. systemctl disable --now nginx apache2 caddy) and re-run."; fi
  done

  check_dns
}

public_ip() { curl "-$1" -fsS --max-time 6 "https://api$([ "$1" = 6 ] && echo 6).ipify.org" 2>/dev/null || true; }

resolve() {
  if command -v dig >/dev/null 2>&1; then
    { dig +short A "$1" @1.1.1.1; dig +short AAAA "$1" @1.1.1.1; } 2>/dev/null | grep -E '^[0-9a-fA-F:.]+$' || true
  else
    getent ahosts "$1" 2>/dev/null | awk '{print $1}' | sort -u || true
  fi
}

check_dns() {
  if [ "$SKIP_DNS" = 1 ] || [ "$TLS" = internal ]; then
    warn "DNS check skipped ($([ "$TLS" = internal ] && echo '--tls internal' || echo '--skip-dns-check'))"
    return 0
  fi
  local ip4 ip6 host addrs failed=0
  ip4="$(public_ip 4)"; ip6="$(public_ip 6)"
  [ -n "$ip4$ip6" ] || die "could not determine this host's public IP (no internet access?). Use --skip-dns-check if you are sure DNS is right."
  ok "public IP: ${ip4:-} ${ip6:-}"
  for host in "$DOMAIN" "$AGENTS_HOST" $GRAFANA_HOST; do
    addrs="$(resolve "$host")"
    if { [ -n "$ip4" ] && grep -qx "$ip4" <<<"$addrs"; } || { [ -n "$ip6" ] && grep -qx "$ip6" <<<"$addrs"; }; then
      ok "DNS $host -> $(tr '\n' ' ' <<<"$addrs")"
    else
      failed=1
      if [ -z "$addrs" ]; then warn "DNS: $host does not resolve"; else warn "DNS: $host -> $(tr '\n' ' ' <<<"$addrs") (this host is ${ip4:-$ip6})"; fi
    fi
  done
  if [ "$failed" = 1 ]; then
    printf '\n  Create these DNS records at your DNS provider, wait until they resolve, then re-run:\n\n' >&2
    for host in "$DOMAIN" "$AGENTS_HOST" $GRAFANA_HOST; do
      [ -n "$ip4" ] && printf '    %-40s A     %s\n' "$host" "$ip4" >&2
      [ -n "$ip6" ] && printf '    %-40s AAAA  %s\n' "$host" "$ip6" >&2
    done
    printf '\n  (Cloudflare: DNS only / grey cloud — the agent API uses its own certificate and mTLS.)\n' >&2
    die "DNS does not point at this host yet (or re-run with --skip-dns-check)"
  fi
}

# --- Docker ---------------------------------------------------------------------------------------------
install_docker() {
  info "Docker"
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "$(docker --version)"
  else
    local id codename
    id="$(os_field ID)"; codename="$(os_field VERSION_CODENAME)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg >/dev/null
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$id/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$id $codename stable" \
      > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
    systemctl enable --now docker >/dev/null 2>&1 || true
    ok "installed $(docker --version)"
  fi
  docker info >/dev/null 2>&1 || die "the Docker daemon is not running (systemctl status docker)"
  local cv major minor
  cv="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
  major="${cv%%.*}"; minor="$(echo "$cv" | cut -d. -f2)"
  if [ "${major:-0}" -lt 2 ] || { [ "$major" = 2 ] && [ "${minor:-0}" -lt 24 ]; }; then
    die "Docker Compose $cv is too old (need 2.24+): apt-get install --only-upgrade docker-compose-plugin"
  fi
  ok "Docker Compose $cv"
  command -v openssl >/dev/null 2>&1 || apt-get install -y -qq openssl >/dev/null 2>&1 || true
}

# --- release / deploy files -----------------------------------------------------------------------------
resolve_version() {
  if [ -n "$VERSION" ]; then return 0; fi
  if [ "$FROM_SOURCE" = 1 ]; then VERSION="${REF:-main}"; return 0; fi
  VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" 2>/dev/null \
    | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -1 || true)"
  [ -n "$VERSION" ] || die "could not find the latest release of github.com/$REPO (pass --version vX.Y.Z)"
}

fetch_files() {
  info "Kiln $VERSION files"
  local stage
  stage="$(mktemp -d)"
  if [ "$FROM_SOURCE" = 1 ] && [ -z "$SOURCE_DIR" ]; then
    command -v git >/dev/null 2>&1 || apt-get install -y -qq git >/dev/null
    rm -rf "$KILN_DIR/src"
    git clone -q --depth 1 --branch "${REF:-$VERSION}" "https://github.com/$REPO.git" "$KILN_DIR/src" \
      || die "git clone of github.com/$REPO (${REF:-$VERSION}) failed"
    SOURCE_DIR="$KILN_DIR/src"
  fi
  if [ -n "$SOURCE_DIR" ]; then
    [ -f "$SOURCE_DIR/deploy/compose.yml" ] || die "$SOURCE_DIR has no deploy/compose.yml"
    cp -R "$SOURCE_DIR/deploy" "$stage/deploy"
    cp -R "$SOURCE_DIR/observability" "$stage/observability"
    ok "from $SOURCE_DIR"
  else
    local base="https://github.com/$REPO/releases/download/$VERSION"
    curl -fsSL --retry 3 -o "$stage/kiln-deploy.tar.gz" "$base/kiln-deploy.tar.gz" \
      || die "download failed: $base/kiln-deploy.tar.gz (does release $VERSION exist?)"
    curl -fsSL --retry 3 -o "$stage/SHA256SUMS" "$base/SHA256SUMS" || die "download failed: $base/SHA256SUMS"
    (cd "$stage" && grep ' kiln-deploy.tar.gz$' SHA256SUMS | sha256sum -c --quiet -) || die "checksum mismatch for kiln-deploy.tar.gz"
    tar -xzf "$stage/kiln-deploy.tar.gz" -C "$stage"
    ok "release bundle verified (SHA256SUMS)"
  fi
  local d
  for d in deploy observability; do
    rm -rf "${KILN_DIR:?}/$d.prev"
    [ -d "$KILN_DIR/$d" ] && mv "$KILN_DIR/$d" "$KILN_DIR/$d.prev"
    mv "$stage/$d" "$KILN_DIR/$d"
  done
  rm -rf "$stage"
  install -m 0755 "$KILN_DIR/deploy/kiln-ctl" /usr/local/bin/kiln-ctl
  ok "kiln-ctl installed to /usr/local/bin/kiln-ctl"
}

build_images() {
  info "Building images from source ($SOURCE_DIR, this takes a while)"
  local src="$SOURCE_DIR"
  docker build -q -f "$src/control-plane/Dockerfile" --build-arg KILN_VERSION="$VERSION" -t "$IMAGE_PREFIX/kiln-control-plane:$VERSION" "$src" >/dev/null
  ok "kiln-control-plane"
  docker build -q -f "$src/deploy/builder.Dockerfile" --build-arg KILN_VERSION="$VERSION" -t "$IMAGE_PREFIX/kiln-builder:$VERSION" "$src" >/dev/null
  ok "kiln-builder"
  docker build -q -f "$src/deploy/edge/Dockerfile" --build-arg KILN_VERSION="$VERSION" -t "$IMAGE_PREFIX/kiln-edge:$VERSION" "$src/deploy/edge" >/dev/null
  ok "kiln-edge"
}

# --- .env -----------------------------------------------------------------------------------------------
write_env() {
  info "Configuration ($ENV_FILE)"
  umask 077
  local fresh=0
  [ -f "$ENV_FILE" ] || { fresh=1; printf '# Kiln settings and secrets — generated by install.sh. Keep private; back it up (kiln-ctl backup).\n' > "$ENV_FILE"; }
  chmod 600 "$ENV_FILE"

  # secrets: generated once, never rotated by re-runs
  env_default APP_KEY "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  env_default DB_PASSWORD "$(rand_hex 24)"
  env_default REDIS_PASSWORD "$(rand_hex 24)"
  env_default REVERB_APP_ID "kiln"
  env_default REVERB_APP_KEY "$(rand_hex 10)"
  env_default REVERB_APP_SECRET "$(rand_hex 20)"
  env_default KILN_BUILDER_TOKEN "kbt_$(rand_hex 24)"
  env_default KILN_OTLP_TOKEN "$(rand_hex 24)"
  env_default GRAFANA_ADMIN_PASSWORD "$(rand_hex 16)"

  # settings: follow the flags
  env_set KILN_REPO "$REPO"
  env_set KILN_IMAGE_PREFIX "$IMAGE_PREFIX"
  env_set KILN_VERSION "$VERSION"
  env_set KILN_DOMAIN "$DOMAIN"
  env_set KILN_URL "https://$DOMAIN$PORT_SUFFIX"
  env_set KILN_AGENT_API_HOST "$AGENTS_HOST"
  env_set KILN_AGENT_API_URL "https://$AGENTS_HOST$PORT_SUFFIX/agent/v1"
  env_set KILN_ACME_EMAIL "$EMAIL"
  env_set KILN_TLS "$TLS"
  env_set KILN_HTTP_PORT "$HTTP_PORT"
  env_set KILN_HTTPS_PORT "$HTTPS_PORT"
  if [ "$TLS" = internal ]; then env_set KILN_HSTS "max-age=0"; else env_set KILN_HSTS "max-age=31536000"; fi
  env_default KILN_EDGE_SUBNET "10.213.77.0/24"
  env_default KILN_BACKUP_KEEP "14"
  if [ -n "$SOURCE_DIR" ]; then env_set KILN_DEPLOY_SOURCE "$SOURCE_DIR"; fi
  if [ "$FROM_SOURCE" = 1 ]; then env_set KILN_PULL 0; else env_default KILN_PULL 1; fi

  if [ "$OBSERVABILITY" = 1 ]; then
    env_set COMPOSE_PROFILES observability
    env_set KILN_GRAFANA_HOST "$GRAFANA_HOST"
    env_set KILN_GRAFANA_PUBLIC_URL "https://$GRAFANA_HOST$PORT_SUFFIX"
    env_set KILN_GRAFANA_URL "http://grafana:3000"
    env_set KILN_LOKI_URL "http://loki:3100"
    env_set KILN_TEMPO_URL "http://tempo:3200"
    env_set KILN_METRICS_QUERY_URL "http://gateway:9090/prometheus"
    env_set KILN_OTLP_ENDPOINT "https://$DOMAIN$PORT_SUFFIX/otlp"
  else
    local k
    for k in COMPOSE_PROFILES KILN_GRAFANA_HOST KILN_GRAFANA_PUBLIC_URL KILN_GRAFANA_URL KILN_LOKI_URL KILN_TEMPO_URL KILN_METRICS_QUERY_URL KILN_OTLP_ENDPOINT; do
      env_set "$k" ""
    done
  fi
  if [ "$fresh" = 1 ]; then ok "generated new secrets"; else ok "kept existing secrets, updated settings"; fi
}

check_subnet() {
  local subnet prefix
  subnet="$(env_get KILN_EDGE_SUBNET)"; prefix="${subnet%.*}."
  if ip route 2>/dev/null | grep -v 'br-' | grep -q "^${prefix}"; then
    die "KILN_EDGE_SUBNET $subnet overlaps a network on this host. Set another /24 in $ENV_FILE (KILN_EDGE_SUBNET=...) and re-run."
  fi
}

# --- start ----------------------------------------------------------------------------------------------
kctl() { KILN_DIR="$KILN_DIR" KILN_PROJECT="$KILN_PROJECT" /usr/local/bin/kiln-ctl "$@"; }
dc() { COMPOSE_PROFILES="$(env_get COMPOSE_PROFILES)" docker compose -p "$KILN_PROJECT" --env-file "$ENV_FILE" -f "$KILN_DIR/deploy/compose.yml" "$@"; }

start_stack() {
  info "Starting Kiln $VERSION"
  if [ "$(env_get KILN_PULL 1)" != 0 ]; then
    dc pull --quiet || die "pulling images from $IMAGE_PREFIX failed (is release $VERSION published? private packages need 'docker login ghcr.io')"
    ok "images pulled"
  fi
  if ! dc up -d --wait --wait-timeout 600 --remove-orphans; then
    dc ps -a >&2 || true
    dc logs --tail=60 control-plane agent-api edge >&2 || true
    die "the stack did not become healthy — see the logs above, then: kiln-ctl doctor"
  fi
  ok "all services healthy"
}

setup_grafana_token() {
  [ "$OBSERVABILITY" = 1 ] || return 0
  [ -z "$(env_get KILN_GRAFANA_TOKEN)" ] || return 0
  info "Grafana service account for Kiln"
  local pass sa token
  pass="$(env_get GRAFANA_ADMIN_PASSWORD)"
  sa="$(dc exec -T grafana sh -c "wget -q -O - --header 'Content-Type: application/json' --post-data '{\"name\":\"kiln\",\"role\":\"Admin\"}' http://admin:$pass@127.0.0.1:3000/api/serviceaccounts" 2>/dev/null \
    | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1 || true)"
  if [ -z "$sa" ]; then
    sa="$(dc exec -T grafana sh -c "wget -q -O - 'http://admin:$pass@127.0.0.1:3000/api/serviceaccounts/search?query=kiln'" 2>/dev/null \
      | sed -n 's/.*"serviceAccounts":\[{"id":\([0-9]*\).*/\1/p' | head -1 || true)"
  fi
  [ -n "$sa" ] || { warn "could not create the Grafana service account (set KILN_GRAFANA_TOKEN in $ENV_FILE manually)"; return 0; }
  token="$(dc exec -T grafana sh -c "wget -q -O - --header 'Content-Type: application/json' --post-data '{\"name\":\"kiln-$(date +%s)\"}' http://admin:$pass@127.0.0.1:3000/api/serviceaccounts/$sa/tokens" 2>/dev/null \
    | sed -n 's/.*"key":"\([^"]*\)".*/\1/p' | head -1 || true)"
  [ -n "$token" ] || { warn "could not create a Grafana token (set KILN_GRAFANA_TOKEN manually)"; return 0; }
  env_set KILN_GRAFANA_TOKEN "$token"
  dc up -d --wait --wait-timeout 300 >/dev/null
  ok "KILN_GRAFANA_TOKEN configured"
}

create_admin() {
  info "First administrator"
  local out password
  out="$(dc exec -T control-plane php artisan kiln:admin "$ADMIN_EMAIL" --json 2>/dev/null | tail -1 || true)"
  printf '%s' "$out" | grep -q '"user_id"' || die "kiln:admin failed: $out (retry: kiln-ctl admin create $ADMIN_EMAIL)"
  password="$(printf '%s' "$out" | sed -n 's/.*"password":"\([^"]*\)".*/\1/p')"
  if [ -n "$password" ]; then
    ADMIN_PASSWORD="$password"
    ok "created $ADMIN_EMAIL"
  else
    ADMIN_PASSWORD=""
    ok "$ADMIN_EMAIL already exists (password unchanged; reset: kiln-ctl admin reset-password $ADMIN_EMAIL)"
  fi
}

summary() {
  printf '\n%sKiln %s is running.%s\n\n' "$B" "$VERSION" "$N"
  printf '  Panel        https://%s%s\n' "$DOMAIN" "$PORT_SUFFIX"
  printf '  Agent API    https://%s%s/agent/v1  (mTLS, Fleet CA)\n' "$AGENTS_HOST" "$PORT_SUFFIX"
  [ -n "$GRAFANA_HOST" ] && printf '  Grafana      https://%s%s  (admin / GRAFANA_ADMIN_PASSWORD in %s)\n' "$GRAFANA_HOST" "$PORT_SUFFIX" "$ENV_FILE"
  if [ -n "${ADMIN_PASSWORD:-}" ]; then
    printf '\n  %sAdmin login  %s\n  Password     %s%s\n' "$B" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$N"
    printf '  (shown once — store it in a password manager)\n'
  fi
  printf '\n  Next: kiln-ctl status | kiln-ctl doctor | kiln-ctl backup\n'
  printf '  %sBack up regularly and copy backups off this host:%s they contain the Fleet CA — losing it\n' "$Y" "$N"
  printf '  means re-enrolling every server. Docs: https://github.com/%s/blob/main/docs/INSTALL.md\n\n' "$REPO"
}

main() {
  preflight
  install_docker
  resolve_version
  fetch_files
  write_env
  check_subnet
  if [ "$FROM_SOURCE" = 1 ]; then build_images; fi
  start_stack
  setup_grafana_token
  create_admin
  summary
}

# stdin is the script itself under `curl | bash`: never let a command read it.
main </dev/null
