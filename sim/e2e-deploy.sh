#!/usr/bin/env bash
# Full product E2E on the local simulation, driven ONLY through public surfaces:
#   `artisan kiln:admin` (first-run bootstrap) → REST API /api/v1 → the install command each server prints.
#
#   ./e2e-deploy.sh            run every stage
#   STAGES="servers" ./e2e-deploy.sh   run selected stages (space-separated)
#
# Requires `make up`. State for later stages is kept in .data/e2e.env.
set -uo pipefail
cd "$(dirname "$0")"
set -a; . ./sim.env; . ./.data/secrets.env; set +a

C=(docker compose -p kiln-sim --env-file sim.env --env-file .data/secrets.env)
EDGE="https://kiln.test:${SIM_EDGE_HTTPS_PORT}"
CURL=(curl -sS --cacert .data/edge-root.crt --resolve "kiln.test:${SIM_EDGE_HTTPS_PORT}:127.0.0.1")
STATE=.data/e2e.env
STAGES="${STAGES:-bootstrap servers}"

pass=0 fail=0 API_CODE=000 API_BODY='' KILN_TOKEN=${KILN_TOKEN:-}
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$*"; pass=$((pass + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$*"; fail=$((fail + 1)); }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
save() { printf -v "$1" '%s' "$2"; grep -v "^$1=" "$STATE" 2>/dev/null > "$STATE.tmp"; printf '%s=%q\n' "$1" "$2" >> "$STATE.tmp"; mv "$STATE.tmp" "$STATE"; }
[[ -f $STATE ]] && . "$STATE"
[[ -s .data/edge-root.crt ]] || make -s ca >/dev/null

api() { # api METHOD PATH [JSON] -> sets $API_BODY and $API_CODE (call directly, not in $(...))
    local out; out=$(mktemp)
    API_CODE=$("${CURL[@]}" -o "$out" -w '%{http_code}' -X "$1" "${EDGE}/api/v1$2" \
        -H "Authorization: Bearer ${KILN_TOKEN}" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        ${3:+--data "$3"}) || API_CODE=000
    API_BODY=$(cat "$out"); rm -f "$out"
}

stage_bootstrap() {
    step "bootstrap: first admin + API token via kiln:admin"
    local json
    json=$("${C[@]}" exec -T control-plane php artisan kiln:admin admin@kiln.test --organization="Kiln E2E" --token="e2e-$(date +%s)" --json 2>/dev/null | tail -1)
    KILN_TOKEN=$(jq -r '.token // empty' <<<"$json")
    if [[ -n $KILN_TOKEN ]]; then
        save KILN_TOKEN "$KILN_TOKEN"; save KILN_ORG "$(jq -r .organization_id <<<"$json")"
        ok "admin@kiln.test owns organization $(jq -r .organization <<<"$json")"
    else
        bad "kiln:admin did not return a token: $json"; return 1
    fi

    api GET /me
    [[ $API_CODE == 200 ]] && ok "GET /api/v1/me -> 200 ($(jq -r '.data.email // .data.user.email // "?"' <<<"$API_BODY"))" || bad "GET /api/v1/me -> $API_CODE: $API_BODY"
}

server_status() { api GET "/servers/$1"; jq -r '.data.status // empty' <<<"$API_BODY"; }

provision_server() { # provision_server NAME CONTAINER TYPE STACK_JSON
    local name=$1 container=$2 type=$3 stack=$4 body id cmd
    api POST /servers "{\"name\":\"$name\",\"type\":\"$type\",\"provider\":\"custom\",\"stack\":$stack}"; body=$API_BODY
    if [[ $API_CODE != 201 ]]; then bad "POST /servers $name -> $API_CODE: $body"; return 1; fi
    id=$(jq -r .data.id <<<"$body"); cmd=$(jq -r .data.install_command <<<"$body")
    save "SERVER_${container//-/_}" "$id"
    ok "created custom $type server $name ($id)"

    [[ -n $cmd && $cmd != null ]] && ok "$name: API returned an install command" || { bad "$name: no install command"; return 1; }
    if "${C[@]}" exec -T "$container" bash -c "$cmd" > ".data/install-$container.log" 2>&1; then
        ok "$name: install command ran on $container (log: .data/install-$container.log)"
    else
        bad "$name: install command failed on $container (see .data/install-$container.log)"; return 1
    fi
}

stage_servers() {
    step "servers: create via API, install agent, real provisioning on Ubuntu 24.04"
    local php='{"php":{"runtime":"frankenphp","versions":["8.4"],"default":"8.4"},"node":"22"}'
    provision_server app-1 srv-app-1 app "$php" || true
    provision_server app-2 srv-app-2 app "$php" || true
    provision_server db-1 srv-db-1 db '{"database":"postgresql"}' || true

    for c in srv-app-1 srv-app-2 srv-db-1; do
        local var="SERVER_${c//-/_}" id; id=${!var:-}
        [[ -z $id ]] && continue
        local s="" deadline=$((SECONDS + 1200))
        while (( SECONDS < deadline )); do
            s=$(server_status "$id")
            [[ $s == active || $s == error ]] && break
            sleep 10
        done
        [[ $s == active ]] && ok "$c provisioned -> active" || bad "$c provisioning ended in '${s:-unknown}'"
    done

    for c in srv-app-1 srv-app-2; do
        "${C[@]}" exec -T "$c" bash -c 'command -v frankenphp >/dev/null && frankenphp version' >/dev/null 2>&1 \
            && ok "$c: frankenphp installed" || bad "$c: frankenphp missing"
        "${C[@]}" exec -T "$c" php8.4 -v >/dev/null 2>&1 || "${C[@]}" exec -T "$c" php -v >/dev/null 2>&1 \
            && ok "$c: php CLI installed" || bad "$c: php CLI missing"
        "${C[@]}" exec -T "$c" node --version >/dev/null 2>&1 && ok "$c: node installed" || bad "$c: node missing"
    done
    "${C[@]}" exec -T srv-db-1 bash -c 'systemctl is-active postgresql' 2>/dev/null | grep -q active \
        && ok "srv-db-1: postgresql running" || bad "srv-db-1: postgresql not running"
    for c in srv-app-1 srv-app-2 srv-db-1; do
        "${C[@]}" exec -T "$c" bash -c 'nft list ruleset 2>/dev/null | grep -q "dport 22"' && ok "$c: nftables firewall applied" || bad "$c: firewall ruleset missing"
    done
}

for s in $STAGES; do "stage_$s"; done

printf '\n==> e2e-deploy: %d passed, %d failed\n' "$pass" "$fail"
(( fail == 0 ))
