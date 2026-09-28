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
STAGES="${STAGES:-bootstrap servers sites deploy release rollback failure octane bun release_env waiting observability compose compose_redeploy compose_failure templates}"

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
    # app-2 also runs Docker (compose sites): nested dockerd on the srv-app-2-docker volume.
    provision_server app-2 srv-app-2 app '{"php":{"runtime":"frankenphp","versions":["8.4"],"default":"8.4"},"node":"22","docker":true}' || true
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
        [[ "$("${C[@]}" exec -T "$c" bash -c 'exec 3<>/dev/tcp/127.0.0.1/22; head -c 7 <&3' 2>/dev/null)" == SSH-2.0 ]] \
            && ok "$c: SSH still answering on :22 after provisioning" || bad "$c: SSH not answering after provisioning"
    done
    for c in srv-app-1 srv-app-2 srv-db-1; do
        "${C[@]}" exec -T "$c" bash -c 'nft list ruleset 2>/dev/null | grep -q "dport 22"' && ok "$c: nftables firewall applied" || bad "$c: firewall ruleset missing"
    done
}

# ---------------------------------------------------------------------------------------------- sites/deploys
git_head() { "${C[@]}" exec -T sim-git git --git-dir="/srv/git/$1.git" rev-parse main 2>/dev/null | tr -d '\r'; }

git_commit() { # git_commit REPO MESSAGE SHELL_SNIPPET  -> new commit on main in the sim git server
    "${C[@]}" exec -T sim-git sh -c "set -e; rm -rf /tmp/w; git clone -q /srv/git/$1.git /tmp/w; cd /tmp/w; $3; git add -A; git commit -q -m '$2'; git push -q origin main" >/dev/null 2>&1
}

deployment_status() { api GET "/deployments/$1"; jq -r '.data.status // empty' <<<"$API_BODY"; }

wait_targets_ready() { # wait_targets_ready SITE_ID  (site targets finish preparing, e.g. runtime install)
    local deadline=$((SECONDS + 1900)) states=""
    while (( SECONDS < deadline )); do
        api GET "/sites/$1"
        states=$(jq -r '[.data.targets[].status] | unique | join(",")' <<<"$API_BODY")
        [[ $states == ready ]] && return 0
        [[ $states == *failed* ]] && break
        sleep 5
    done
    bad "site targets not ready: $states $(jq -c '[.data.targets[] | {server_name, status, status_message}]' <<<"$API_BODY")"
    return 1
}

deploy_and_wait() { # deploy_and_wait SITE_ID COMMIT LABEL EXPECT(succeeded|failed) -> sets DEPLOYMENT_ID
    local site=$1 commit=$2 label=$3 expect=$4 s="" deadline=$((SECONDS + 1500))
    wait_targets_ready "$site" || return 1
    api POST "/sites/$site/deployments" "{\"commit\":\"$commit\"}"
    if [[ $API_CODE != 201 ]]; then bad "$label: POST deployments -> $API_CODE: $API_BODY"; return 1; fi
    DEPLOYMENT_ID=$(jq -r .data.id <<<"$API_BODY")
    while (( SECONDS < deadline )); do
        s=$(deployment_status "$DEPLOYMENT_ID")
        [[ $s == succeeded || $s == failed || $s == cancelled ]] && break
        sleep 5
    done
    api GET "/deployments/$DEPLOYMENT_ID"
    local detail; detail=$(jq -c '{status: .data.status, phase: .data.phase, rolled_back: .data.rolled_back, error: .data.error}' <<<"$API_BODY")
    if [[ $s == "$expect" ]]; then ok "$label: deployment $s $detail"; else
        bad "$label: deployment ended '$s' (wanted $expect) $detail"
        api GET "/deployments/$DEPLOYMENT_ID/output?after=-1"
        jq -r '.data[-25:][] | "      [\(.server // "-")/\(.phase // "-")] \(.data | rtrimstr("\n"))"' <<<"$API_BODY" 2>/dev/null
        return 1
    fi
}

site_get() { # site_get CONTAINER HOST PATH -> body, via the server's own Caddy (internal CA, so -k)
    "${C[@]}" exec -T "$1" curl -sSk -L -m 15 --resolve "$2:443:127.0.0.1" --resolve "$2:80:127.0.0.1" "https://$2$3" 2>/dev/null
}

stage_sites() {
    step "sites: custom git connection + multi-server Laravel site"
    api POST /source-control/connections '{"provider":"custom","auth_type":"none","name":"sim git"}'
    [[ $API_CODE == 201 ]] && { save GIT_CONNECTION "$(jq -r .data.id <<<"$API_BODY")"; ok "custom git connection created"; } || { bad "connection -> $API_CODE: $API_BODY"; return 1; }

    api POST /sites "{\"name\":\"shop\",\"framework\":\"laravel\",\"runtime\":\"frankenphp\",\"php_version\":\"8.4\",\"server_ids\":[\"$SERVER_srv_app_1\",\"$SERVER_srv_app_2\"],\"leader_server_id\":\"$SERVER_srv_app_1\",\"source_connection_id\":\"$GIT_CONNECTION\",\"repository\":\"git://sim-git/laravel-demo.git\",\"branch\":\"main\",\"health_check_path\":\"/health\",\"test_domain_enabled\":true}"
    if [[ $API_CODE == 201 ]]; then
        save SITE_SHOP "$(jq -r .data.id <<<"$API_BODY")"
        save SHOP_HOST "$(jq -r '.data.test_domain // .data.domains[0].name // .data.domains[0] // "shop.sites.kiln.test"' <<<"$API_BODY")"
        ok "site shop created on app-1 (leader) + app-2 -> $SHOP_HOST ($(jq -r '.data.targets | length' <<<"$API_BODY") targets)"
        # No database in this demo: in-memory sqlite for `migrate`, cookie sessions, file cache, sync queue.
        api GET "/sites/$SITE_SHOP/env"
        local env; env=$(jq -r '.data.content' <<<"$API_BODY" | grep -vE '^(DB_CONNECTION|DB_DATABASE|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION)=')
        env+=$'\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nSESSION_DRIVER=cookie\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\n'
        api PUT "/sites/$SITE_SHOP/env" "$(jq -n --arg c "$env" '{content: $c}')"
        [[ $API_CODE == 200 ]] && ok "site environment updated via API (version $(jq -r '.data.version // "?"' <<<"$API_BODY"))" || bad "PUT env -> $API_CODE: $API_BODY"
    else
        bad "POST /sites -> $API_CODE: $API_BODY"; return 1
    fi
}

stage_deploy() {
    step "deploy: build once (kiln-builder native), fetch+prepare all, migrate leader, activate all, health check"
    local commit; commit=$(git_head laravel-demo); save SHOP_COMMIT_1 "$commit"
    deploy_and_wait "$SITE_SHOP" "$commit" "first deploy" succeeded || return 1
    save SHOP_DEPLOY_1 "$DEPLOYMENT_ID"
    local targets; targets=$(jq -r '[.data.targets[] | "\(.server_name):\(.status)"] | join(" ")' <<<"$API_BODY"); ok "targets: $targets"
    local migrate; migrate=$(jq -r '[.data.targets[] | select(any(.steps[]?; .phase == "migrate")) | .server_name] | unique | join(",")' <<<"$API_BODY")
    [[ $migrate == app-1 ]] && ok "migrations ran on the leader only ($migrate)" || bad "migrations ran on: '${migrate:-none}' (expected app-1)"

    for c in srv-app-1 srv-app-2; do
        local body; body=$(site_get "$c" "$SHOP_HOST" /)
        local rel; rel=$(jq -r '.release // empty' 2>/dev/null <<<"$body")
        local want; want=$(basename "$("${C[@]}" exec -T "$c" bash -c 'readlink /srv/kiln/sites/shop/current' | tr -d '\r')")
        [[ -n $rel && $rel == "$want" ]] && ok "$c serves the Laravel app from the active release ($rel)" || bad "$c: GET / -> ${body:0:200} (current: $want)"
        [[ $c == srv-app-1 ]] && save SHOP_RELEASE_1 "$rel"
        [[ "$(site_get "$c" "$SHOP_HOST" /health)" == ok ]] && ok "$c: /health ok" || bad "$c: /health failed"
        "${C[@]}" exec -T "$c" bash -c 'readlink /srv/kiln/sites/*/current' >/dev/null 2>&1 && ok "$c: current -> $("${C[@]}" exec -T "$c" bash -c 'basename $(readlink /srv/kiln/sites/*/current)' | tr -d '\r')" || bad "$c: no current symlink"
    done
}

stage_release() {
    step "release: second commit deploys zero-downtime; releases list shows it active"
    git_commit laravel-demo "Second release" "sed -i 's/Kiln Demo/Kiln Demo v2/' .env.example && sed -i \"s/'app' => config('app.name')/'app' => 'Kiln Demo v2'/\" routes/web.php" \
        && ok "pushed a second commit" || { bad "could not push second commit"; return 1; }
    local commit; commit=$(git_head laravel-demo); save SHOP_COMMIT_2 "$commit"
    deploy_and_wait "$SITE_SHOP" "$commit" "second deploy" succeeded || return 1
    for c in srv-app-1 srv-app-2; do
        [[ "$(site_get "$c" "$SHOP_HOST" / | jq -r .app 2>/dev/null)" == "Kiln Demo v2" ]] && ok "$c serves v2" || bad "$c does not serve v2"
    done
    api GET "/sites/$SITE_SHOP/releases"
    [[ "$(jq -r '.data[0].commit' <<<"$API_BODY")" == "$commit" && "$(jq -r '.data[0].active' <<<"$API_BODY")" == true ]] \
        && ok "releases: newest is active ($(jq -r '.data | length' <<<"$API_BODY") retained)" || bad "releases: $API_BODY"
}

stage_rollback() {
    step "rollback: back to release 1 on every server"
    api POST "/sites/$SITE_SHOP/rollback" '{}'
    [[ $API_CODE == 201 ]] || { bad "rollback -> $API_CODE: $API_BODY"; return 1; }
    local id s="" deadline=$((SECONDS + 600)); id=$(jq -r .data.id <<<"$API_BODY")
    while (( SECONDS < deadline )); do s=$(deployment_status "$id"); [[ $s == succeeded || $s == failed ]] && break; sleep 3; done
    [[ $s == succeeded ]] && ok "rollback deployment succeeded" || bad "rollback ended '$s'"
    for c in srv-app-1 srv-app-2; do
        local rel; rel=$(site_get "$c" "$SHOP_HOST" / | jq -r '.release // empty' 2>/dev/null)
        [[ -n $rel && $rel == "$SHOP_RELEASE_1" ]] && ok "$c serves release 1 again ($rel)" || bad "$c not rolled back (serves ${rel:-nothing}, want $SHOP_RELEASE_1)"
    done
}

stage_failure() {
    step "failure: a release whose /health returns 500 is rolled back automatically"
    git_commit laravel-demo "Broken health" "sed -i \"s#Route::get('/health', fn () => response('ok'));#Route::get('/health', fn () => response('broken', 500));#\" routes/web.php" \
        && ok "pushed a broken commit" || { bad "could not push broken commit"; return 1; }
    local before; before=$(site_get srv-app-1 "$SHOP_HOST" / | jq -r .release 2>/dev/null)
    deploy_and_wait "$SITE_SHOP" "$(git_head laravel-demo)" "broken deploy" failed
    [[ "$(jq -r .data.rolled_back <<<"$API_BODY")" == true ]] && ok "deployment reports rolled_back=true" || bad "rolled_back not set: $(jq -c '.data | {status,phase,error}' <<<"$API_BODY")"
    for c in srv-app-1 srv-app-2; do
        local now; now=$(site_get "$c" "$SHOP_HOST" / | jq -r .release 2>/dev/null)
        [[ "$(site_get "$c" "$SHOP_HOST" /health)" == ok && $now == "$before" ]] && ok "$c still serves the previous healthy release" || bad "$c: release=$now health=$(site_get "$c" "$SHOP_HOST" /health)"
    done
}

# ---------------------------------------------------------------------------------------------- octane
octane_get() { site_get srv-app-1 "$OCTANE_HOST" "$1"; }

octane_max_served() { # octane_max_served N -> highest per-worker request counter over N requests to /octane (1 = no worker mode)
    local max=0 n
    for _ in $(seq 1 "$1"); do
        n=$(octane_get /octane | jq -r '.served // 0' 2>/dev/null)
        (( ${n:-0} > max )) && max=$n
    done
    echo "$max"
}

octane_wait_mode() { # octane_wait_mode on|off SECONDS -> 0 when the edge serves /octane in that mode
    local want=$1 deadline=$((SECONDS + $2)) served=0
    while (( SECONDS < deadline )); do
        served=$(octane_max_served 8)
        [[ $want == on && $served -gt 1 ]] && return 0
        [[ $want == off && $served -eq 1 ]] && return 0
        sleep 5
    done
    return 1
}

octane_load_start() { # background request loop on srv-app-1 through the edge; one HTTP status per line
    "${C[@]}" exec -T srv-app-1 bash -c 'rm -f /tmp/octane-codes /tmp/octane-stop' >/dev/null 2>&1
    "${C[@]}" exec -d srv-app-1 bash -c "while [ ! -f /tmp/octane-stop ]; do curl -sk -o /dev/null -w '%{http_code}\n' -m 60 --resolve '$OCTANE_HOST:443:127.0.0.1' 'https://$OCTANE_HOST/octane' >> /tmp/octane-codes; sleep 0.1; done"
}

octane_load_stop() { # -> "TOTAL FAILED" (non-200 answers, including curl errors = 000)
    "${C[@]}" exec -T srv-app-1 bash -c 'touch /tmp/octane-stop; sleep 3; total=$(wc -l < /tmp/octane-codes); failed=$(grep -vc "^200$" /tmp/octane-codes); echo "$total $failed"; grep -v "^200$" /tmp/octane-codes | sort | uniq -c | head -5 >&2' | tr -d '\r'
}

stage_octane() {
    step "octane: Laravel Octane (FrankenPHP worker mode) behind the edge; placeholder, zero-downtime redeploy, switch off"
    api POST /sites "{\"name\":\"octane\",\"framework\":\"laravel\",\"runtime\":\"frankenphp\",\"php_version\":\"8.4\",\"server_ids\":[\"$SERVER_srv_app_1\"],\"source_connection_id\":\"$GIT_CONNECTION\",\"repository\":\"git://sim-git/laravel-demo.git\",\"branch\":\"main\",\"health_check_path\":\"/health\",\"test_domain_enabled\":true}"
    [[ $API_CODE == 201 ]] || { bad "POST /sites (octane) -> $API_CODE: $API_BODY"; return 1; }
    save SITE_OCTANE "$(jq -r .data.id <<<"$API_BODY")"
    save OCTANE_HOST "$(jq -r '.data.test_domain // "octane.sites.kiln.test"' <<<"$API_BODY")"
    ok "site octane created on app-1 -> $OCTANE_HOST"
    api GET "/sites/$SITE_OCTANE/env"
    local env; env=$(jq -r '.data.content' <<<"$API_BODY" | grep -vE '^(DB_CONNECTION|DB_DATABASE|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION)=')
    env+=$'\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nSESSION_DRIVER=cookie\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\n'
    api PUT "/sites/$SITE_OCTANE/env" "$(jq -n --arg c "$env" '{content: $c}')"
    [[ $API_CODE == 200 ]] && ok "environment set" || bad "PUT env -> $API_CODE: $API_BODY"

    # Octane on before the first deploy: the program cannot start on the placeholder release, and the edge must
    # keep serving the placeholder directly (never proxy to a port nothing listens on).
    wait_targets_ready "$SITE_OCTANE" || return 1
    api PUT "/sites/$SITE_OCTANE/laravel" '{"octane":true}'
    [[ $API_CODE == 200 ]] || { bad "PUT laravel octane=true -> $API_CODE: $API_BODY"; return 1; }
    local port server; port=$(jq -r .data.octane_port <<<"$API_BODY"); server=$(jq -r .data.octane_server <<<"$API_BODY")
    save OCTANE_PORT "$port"
    [[ $server == frankenphp && $port =~ ^[0-9]+$ ]] && ok "octane enabled via API: server $server, port $port" || bad "octane settings: $API_BODY"
    sleep 15
    local body; body=$(octane_get /)
    [[ $body == *"not been deployed yet"* ]] && ok "never-deployed site still serves the placeholder through the edge" || bad "placeholder not served: ${body:0:200}"

    # Healthy commit (the failure stage may have left a broken /health on main).
    git_commit laravel-demo "Octane v1" "sed -i \"s#response('broken', 500)#response('ok')#\" routes/web.php && sed -i \"s/'app' => config('app.name')/'app' => 'Kiln Octane v1'/; s/'app' => 'Kiln Demo v2'/'app' => 'Kiln Octane v1'/\" routes/web.php" \
        && ok "pushed Octane v1" || { bad "could not push Octane v1"; return 1; }
    deploy_and_wait "$SITE_OCTANE" "$(git_head laravel-demo)" "octane first deploy" succeeded || return 1

    if octane_wait_mode on 240; then ok "the edge proxies to Octane: /octane answers from long-lived workers (max served $(octane_max_served 8))"
    else bad "edge not serving from Octane workers: $(octane_get /octane)"; fi
    "${C[@]}" exec -T srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null && ok "octane listens on 127.0.0.1:$port" || bad "nothing listens on 127.0.0.1:$port"
    "${C[@]}" exec -T srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$((port + 10000))" 2>/dev/null && ok "octane's FrankenPHP admin API on its own port $((port + 10000)) (edge keeps :2019)" || bad "no octane admin port $((port + 10000))"
    [[ "$(octane_get /)" == *'"Kiln Octane v1"'* ]] && ok "serves release v1 through Octane" || bad "GET / -> $(octane_get /)"
    [[ "$(octane_get /kiln-static.txt)" == "static.txt served by Caddy" ]] && ok "public/ files are served directly" || bad "static file -> $(octane_get /kiln-static.txt | head -c 120)"
    [[ "$(octane_get /frankenphp-worker.php)" != *"<?php"* ]] && ok "PHP sources in public/ are never served as files" || bad "frankenphp-worker.php source exposed"
    [[ "$(octane_get /health)" == ok ]] && ok "/health ok through Octane" || bad "/health -> $(octane_get /health)"

    # Redeploy under load: Octane restarts on the new release while the edge holds requests -> no failed request.
    git_commit laravel-demo "Octane v2" "sed -i \"s/'app' => 'Kiln Octane v1'/'app' => 'Kiln Octane v2'/\" routes/web.php" \
        && ok "pushed Octane v2" || { bad "could not push Octane v2"; return 1; }
    octane_load_start
    sleep 3
    deploy_and_wait "$SITE_OCTANE" "$(git_head laravel-demo)" "octane redeploy under load" succeeded
    sleep 5
    local result total failed; result=$(octane_load_stop); total=${result% *}; failed=${result#* }
    (( ${total:-0} > 20 && ${failed:-1} == 0 )) && ok "zero failed requests during the redeploy ($total requests)" || bad "requests during the redeploy: $total total, $failed failed"
    [[ "$(octane_get /)" == *'"Kiln Octane v2"'* ]] && ok "serves release v2 (Octane restarted on the new release)" || bad "not serving v2: $(octane_get /)"
    octane_wait_mode on 60 && ok "still served by Octane workers after the redeploy" || bad "not in worker mode after the redeploy: $(octane_get /octane)"

    # Switch off under load: the edge goes back to FrankenPHP first, then the program stops.
    octane_load_start
    sleep 2
    api PUT "/sites/$SITE_OCTANE/laravel" '{"octane":false}'
    [[ $API_CODE == 200 ]] && ok "octane disabled via API" || bad "PUT laravel octane=false -> $API_CODE: $API_BODY"
    octane_wait_mode off 180 && ok "the edge serves the site directly again (classic FrankenPHP, served=1)" || bad "still proxied after disabling: $(octane_get /octane)"
    local stopped=false deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do
        "${C[@]}" exec -T srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null || { stopped=true; break; }
        sleep 5
    done
    [[ $stopped == true ]] && ok "the octane program stopped after the edge switched back" || bad "octane still listening on $port"
    result=$(octane_load_stop); total=${result% *}; failed=${result#* }
    (( ${total:-0} > 10 && ${failed:-1} == 0 )) && ok "zero failed requests while switching Octane off ($total requests)" || bad "requests while switching off: $total total, $failed failed"
    [[ "$(octane_get /)" == *'"Kiln Octane v2"'* && "$(octane_get /health)" == ok ]] && ok "the app still serves v2 without Octane" || bad "after disabling: $(octane_get /)"
}

stage_bun() {
    step "typescript: Bun/Hono site, native TS build, reverse-proxied runtime"
    api POST /sites "{\"name\":\"api\",\"framework\":\"node\",\"runtime\":\"bun\",\"app_port\":3100,\"server_ids\":[\"$SERVER_srv_app_2\"],\"source_connection_id\":\"$GIT_CONNECTION\",\"repository\":\"git://sim-git/bun-demo.git\",\"branch\":\"main\",\"health_check_path\":\"/health\",\"test_domain_enabled\":true}"
    [[ $API_CODE == 201 ]] || { bad "POST /sites (bun) -> $API_CODE: $API_BODY"; return 1; }
    save SITE_API "$(jq -r .data.id <<<"$API_BODY")"
    save API_HOST "$(jq -r '.data.test_domain // .data.domains[0].name // .data.domains[0] // "api.sites.kiln.test"' <<<"$API_BODY")"
    ok "bun site created -> $API_HOST"
    deploy_and_wait "$SITE_API" "$(git_head bun-demo)" "bun deploy" succeeded || return 1
    local body; body=$(site_get srv-app-2 "$API_HOST" /)
    jq -e '.app == "kiln-bun-demo"' >/dev/null 2>&1 <<<"$body" && ok "srv-app-2 serves the Bun app ($body)" || bad "bun GET / -> ${body:0:200}"
}

proc_env() { # proc_env CONTAINER PATTERN VAR -> VAR from the process environment (/proc/<pid>/environ) of the newest match
    "${C[@]}" exec -T "$1" bash -c "pid=\$(pgrep -nf '$2') && tr '\\0' '\\n' < /proc/\$pid/environ | sed -n 's/^$3=//p'" 2>/dev/null | tr -d '\r'
}

active_release() { # active_release SITE_ID -> upper-case id of the active release
    api GET "/sites/$1/releases"
    jq -r '[.data[] | select(.active)][0].id // empty | ascii_upcase' <<<"$API_BODY"
}

stage_release_env() {
    step "release env: the Bun app's process env carries KILN_RELEASE_ID / KILN_DEPLOYMENT_ID + site variables, and follows each deploy"
    local env
    api GET "/sites/$SITE_API/env"
    env=$(jq -r '.data.content' <<<"$API_BODY" | grep -v '^KILN_E2E_GREETING=')$'\nKILN_E2E_GREETING=hello-from-env\n'
    api PUT "/sites/$SITE_API/env" "$(jq -n --arg c "$env" '{content: $c}')"
    [[ $API_CODE == 200 ]] && ok "site variable KILN_E2E_GREETING set" || bad "PUT env -> $API_CODE: $API_BODY"

    for round in 1 2; do
        git_commit bun-demo "Release env round $round" "echo '// round $round' >> src/index.ts" && ok "pushed bun commit $round" || { bad "could not push bun commit"; return 1; }
        deploy_and_wait "$SITE_API" "$(git_head bun-demo)" "bun deploy (env round $round)" succeeded || return 1
        local want deployment body proc_rel proc_dep proc_greet deadline=$((SECONDS + 60))
        want=$(active_release "$SITE_API")
        api GET "/sites/$SITE_API/releases"; deployment=$(jq -r '[.data[] | select(.active)][0].deployment_id // empty | ascii_upcase' <<<"$API_BODY")
        while (( SECONDS < deadline )); do body=$(site_get srv-app-2 "$API_HOST" /); [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$want" ]] && break; sleep 2; done
        [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$want" && "$(jq -r .deployment <<<"$body" 2>/dev/null)" == "$deployment" ]] \
            && ok "GET / reports KILN_RELEASE_ID=$want KILN_DEPLOYMENT_ID=$deployment (the active release)" || bad "GET / -> ${body:0:300} (want release $want, deployment $deployment)"
        [[ "$(jq -r .greeting <<<"$body" 2>/dev/null)" == hello-from-env ]] && ok "the site variable reaches the app" || bad "greeting: $(jq -r .greeting <<<"$body" 2>/dev/null)"
        # Straight from the supervised process (not a .env the runtime loaded): what proc.apply put into its env.
        proc_rel=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_RELEASE_ID)
        proc_dep=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_DEPLOYMENT_ID)
        proc_greet=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_E2E_GREETING)
        [[ $proc_rel == "$want" && $proc_dep == "$deployment" && $proc_greet == hello-from-env ]] \
            && ok "api.app process env: KILN_RELEASE_ID=$proc_rel KILN_DEPLOYMENT_ID=$proc_dep KILN_E2E_GREETING=$proc_greet" \
            || bad "api.app process env: release='$proc_rel' deployment='$proc_dep' greeting='$proc_greet' (want $want / $deployment)"
    done
}

stage_waiting() {
    step "waiting: a deployment triggered while the new site's server is still preparing waits, coalesces, then succeeds"
    api POST /sites "{\"name\":\"early\",\"framework\":\"node\",\"runtime\":\"bun\",\"app_port\":3101,\"server_ids\":[\"$SERVER_srv_app_1\"],\"source_connection_id\":\"$GIT_CONNECTION\",\"repository\":\"git://sim-git/bun-demo.git\",\"branch\":\"main\",\"health_check_path\":\"/health\",\"test_domain_enabled\":true}"
    [[ $API_CODE == 201 ]] || { bad "POST /sites (early) -> $API_CODE: $API_BODY"; return 1; }
    save SITE_EARLY "$(jq -r .data.id <<<"$API_BODY")"
    save EARLY_HOST "$(jq -r '.data.test_domain // .data.domains[0].name // .data.domains[0] // "early.sites.kiln.test"' <<<"$API_BODY")"
    local targets; targets=$(jq -r '[.data.targets[].status] | join(",")' <<<"$API_BODY")
    ok "site early created on app-1 (targets: $targets)"

    # Immediately: no wait_targets_ready.
    api POST "/sites/$SITE_EARLY/deployments" '{}'
    [[ $API_CODE == 201 ]] || { bad "POST deployments (early) -> $API_CODE: $API_BODY"; return 1; }
    local id status reason
    id=$(jq -r .data.id <<<"$API_BODY"); status=$(jq -r .data.status <<<"$API_BODY"); reason=$(jq -r '.data.waiting_reason // empty' <<<"$API_BODY")
    if [[ $status == waiting ]]; then
        ok "deployment $id waits: $reason"
        api POST "/sites/$SITE_EARLY/deployments" "{\"commit\":\"$(git_head bun-demo)\"}"
        if [[ $(deployment_status "$id") == waiting ]]; then
            [[ $(jq -r .data.id <<<"$API_BODY") == "$id" ]] && ok "a second trigger coalesced into the waiting deployment" || bad "second trigger created another deployment: $API_BODY"
        else
            ok "the server finished preparing before the second trigger (not coalesced)"
        fi
    elif [[ $status == building || $status == deploying ]]; then
        ok "the server was already prepared when the deployment was triggered ($status); waiting not exercised"
    else
        bad "deployment triggered during preparation ended up '$status': $(jq -c '.data | {status, error}' <<<"$API_BODY")"; return 1
    fi

    status=$(wait_deployment "$id")
    [[ $status == succeeded ]] && ok "the waiting deployment started by itself and succeeded" || {
        bad "early deployment ended '$status': $(jq -c '.data | {phase, error}' <<<"$API_BODY")"
        api GET "/deployments/$id/output?after=-1"; jq -r '.data[-25:][] | "      [\(.server // "-")/\(.phase // "-")] \(.data | rtrimstr("\n"))"' <<<"$API_BODY" 2>/dev/null
        return 1; }
    api GET "/deployments/$id/output?after=-1"
    jq -e '[.data[].data] | any(test("finish preparing"))' >/dev/null 2>&1 <<<"$API_BODY" && ok "its log explains the wait" || ok "no wait recorded in its log (the server was ready)"
    local body; body=$(site_get srv-app-1 "$EARLY_HOST" /)
    [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$(active_release "$SITE_EARLY")" ]] && ok "srv-app-1 serves early from its active release" || bad "early GET / -> ${body:0:200}"
}

stage_observability() {
    step "observability: APM traces, exceptions -> Insights issue, deployment events"
    for path in / /work /slow /outgoing /boom /boom; do site_get srv-app-1 "$SHOP_HOST" "$path" >/dev/null; done
    ok "generated traffic on srv-app-1 (requests, job, slow route, outgoing HTTP, 2 exceptions)"
    sleep 20
    local traces
    traces=$(curl -sS "http://127.0.0.1:${KILN_TEMPO_PORT}/api/search?tags=kiln.event.type%3Drequest&limit=20" | jq '.traces | length' 2>/dev/null)
    (( ${traces:-0} > 0 )) && ok "Tempo has $traces request traces from kiln/apm-laravel" || bad "no request traces in Tempo"
    local issues
    issues=$("${C[@]}" exec -T control-plane php artisan tinker --execute='echo DB::table("insights_issues")->where("title","like","%Kiln E2E demo exception%")->count();' 2>/dev/null | tail -1 | tr -d '\r')
    [[ ${issues:-0} -ge 1 ]] && ok "Insights grouped the exception into an issue ($issues)" || bad "no Insights issue for the demo exception"
    local deploys
    deploys=$(curl -sS -G "http://127.0.0.1:${KILN_LOKI_PORT}/loki/api/v1/query_range" --data-urlencode 'query={service_name="kiln-agent"} | kiln_event_type="deployment"' --data-urlencode "start=$(( $(date +%s) - 7200 ))000000000" | jq '[.data.result[].values[]] | length' 2>/dev/null)
    (( ${deploys:-0} > 0 )) && ok "Loki has $deploys deployment lifecycle events from the agents" || bad "no deployment events in Loki"
}

# ---------------------------------------------------------------------------------------------- compose
compose_get() { site_get srv-app-2 "$COMPOSE_HOST" "$1"; }

stage_compose() {
    step "compose: repo compose site (build: service via kiln-builder docker mode + redis volume) through the API"
    "${C[@]}" exec -T srv-app-2 bash -c 'systemctl is-active docker' 2>/dev/null | grep -q active && ok "srv-app-2: docker running" || bad "srv-app-2: docker not running"
    api POST /sites "{\"name\":\"compose-demo\",\"runtime\":\"compose\",\"server_ids\":[\"$SERVER_srv_app_2\"],\"source_connection_id\":\"$GIT_CONNECTION\",\"repository\":\"git://sim-git/compose-demo.git\",\"branch\":\"main\",\"compose_source\":\"repo\",\"public_services\":[{\"service\":\"app\",\"port\":8080}],\"health_check_path\":\"/health\",\"test_domain_enabled\":true}"
    [[ $API_CODE == 201 ]] || { bad "POST /sites (compose) -> $API_CODE: $API_BODY"; return 1; }
    save SITE_COMPOSE "$(jq -r .data.id <<<"$API_BODY")"
    save COMPOSE_HOST "$(jq -r '.data.test_domain // "compose-demo.sites.kiln.test"' <<<"$API_BODY")"
    ok "compose site created -> $COMPOSE_HOST"

    deploy_and_wait "$SITE_COMPOSE" "$(git_head compose-demo)" "compose deploy" succeeded || return 1
    local phases; phases=$(jq -r '[.data.targets[].steps[]?.phase] | unique | join(",")' <<<"$API_BODY")
    ok "phases: $phases"
    save COMPOSE_RELEASE_1 "$(jq -r '.data.release_id // empty' <<<"$API_BODY")"

    local body; body=$(compose_get /)
    jq -e '.app == "kiln-compose-demo" and .greeting == "hello"' >/dev/null 2>&1 <<<"$body" && ok "public URL answers through the edge ($body)" || { bad "compose GET / -> ${body:0:200}"; return 1; }
    [[ "$(compose_get /health)" == ok ]] && ok "/health ok through the edge" || bad "compose /health failed"

    local images; images=$("${C[@]}" exec -T srv-app-2 bash -c 'for c in $(docker ps -q --filter label=kiln.site=compose-demo); do docker inspect "$c" --format "{{index .Config.Labels \"kiln.service\"}}={{.Config.Image}}"; done' 2>/dev/null | tr -d '\r' | sort | tr '\n' ' ')
    [[ $images == *"app=sim-registry:5000/kiln/compose-demo/app@sha256:"* ]] && ok "app runs the built image pinned by digest ($images)" || bad "app image not digest-pinned: $images"
    "${C[@]}" exec -T srv-app-2 bash -c "docker port compose-demo-app-1 8080" 2>/dev/null | grep -q '^127.0.0.1:' && ok "app published on loopback only" || bad "app port not on 127.0.0.1: $("${C[@]}" exec -T srv-app-2 docker port compose-demo-app-1 2>&1)"

    local marker="persist-$(date +%s)"
    save COMPOSE_MARKER "$marker"
    [[ "$(compose_get "/set?value=$marker" | jq -r .marker 2>/dev/null)" == "$marker" ]] && ok "stored $marker in redis" || bad "could not store the marker in redis"

    api GET "/sites/$SITE_COMPOSE"
    ok "site API: runtime $(jq -r .data.runtime <<<"$API_BODY")"

    local lines="" deadline=$((SECONDS + 90))
    while (( SECONDS < deadline )); do
        lines=$(curl -sS -G "http://127.0.0.1:${KILN_LOKI_PORT}/loki/api/v1/query_range" \
            --data-urlencode 'query={service_name="compose-demo"} | kiln_compose_service="app" |~ "kiln-compose-demo listening|GET /"' \
            --data-urlencode "start=$(( $(date +%s) - 3600 ))000000000" | jq '[.data.result[].values[]] | length' 2>/dev/null)
        (( ${lines:-0} > 0 )) && break
        sleep 5
    done
    (( ${lines:-0} > 0 )) && ok "container logs reached Loki (service_name=compose-demo, kiln_compose_service=app: $lines lines)" || bad "no compose container logs in Loki"
}

stage_compose_redeploy() {
    step "compose: a new commit redeploys; the redis named volume keeps its data"
    git_commit compose-demo "Greeting v2" "sed -i 's/APP_GREETING:-hello}/APP_GREETING:-hello v2}/' compose.yaml" && ok "pushed a second compose commit" || { bad "could not push"; return 1; }
    deploy_and_wait "$SITE_COMPOSE" "$(git_head compose-demo)" "compose redeploy" succeeded || return 1
    [[ "$(compose_get / | jq -r .greeting 2>/dev/null)" == "hello v2" ]] && ok "serves the new release (greeting v2)" || bad "not serving v2: $(compose_get /)"
    local got; got=$(compose_get /get | jq -r .marker 2>/dev/null)
    [[ $got == "$COMPOSE_MARKER" ]] && ok "redis volume data survived the redeploy ($got)" || bad "redis data lost: got '$got', want $COMPOSE_MARKER"
}

stage_compose_failure() {
    step "compose: a release whose container healthcheck fails is rolled back to the previous release"
    git_commit compose-demo "Broken health" "sed -i 's/const HEALTHY = true;/const HEALTHY = false;/' app/server.js" && ok "pushed a broken compose commit" || { bad "could not push"; return 1; }
    deploy_and_wait "$SITE_COMPOSE" "$(git_head compose-demo)" "broken compose deploy" failed
    [[ "$(jq -r .data.rolled_back <<<"$API_BODY")" == true ]] && ok "deployment reports rolled_back=true ($(jq -r .data.error <<<"$API_BODY" | head -c 160))" || bad "rolled_back not set: $(jq -c '.data | {status,phase,error}' <<<"$API_BODY")"
    local deadline=$((SECONDS + 60)) health=""
    while (( SECONDS < deadline )); do health=$(compose_get /health); [[ $health == ok ]] && break; sleep 3; done
    [[ $health == ok && "$(compose_get / | jq -r .greeting 2>/dev/null)" == "hello v2" ]] && ok "the previous healthy release serves again" || bad "after rollback: health=$health body=$(compose_get /)"
    [[ "$(compose_get /get | jq -r .marker 2>/dev/null)" == "$COMPOSE_MARKER" ]] && ok "redis data intact after the rollback" || bad "redis data lost after rollback"
}

wait_deployment() { # wait_deployment ID -> final status (sets API_BODY)
    local s="" deadline=$((SECONDS + 1500))
    while (( SECONDS < deadline )); do
        s=$(deployment_status "$1")
        [[ $s == succeeded || $s == failed || $s == cancelled ]] && break
        sleep 5
    done
    api GET "/deployments/$1"
    printf '%s' "$s"
}

deploy_template() { # deploy_template SLUG NAME INPUTS_JSON PROBE_PATH GREP_PATTERN
    local slug=$1 name=$2 inputs=$3 probe=$4 check=$5 project site deployment status host
    api GET /projects
    project=$(jq -r '[.data[] | select(.is_default)][0].id // .data[0].id' <<<"$API_BODY")
    api POST "/projects/$project/production/templates/$slug/deploy" "{\"name\":\"$name\",\"inputs\":$inputs,\"server_ids\":[\"$SERVER_srv_app_2\"]}"
    [[ $API_CODE == 201 ]] || { bad "$slug: POST template deploy -> $API_CODE: $API_BODY"; return 1; }
    site=$(jq -r .data.site_id <<<"$API_BODY"); deployment=$(jq -r '.data.deployment_id // empty' <<<"$API_BODY")
    ok "$slug: template deployed through the API -> site $name ($site)"
    api GET "/sites/$site"
    host=$(jq -r '.data.compose.public_services[0].test_domain // .data.test_domain' <<<"$API_BODY")
    [[ "$(jq -r '.data.compose.template.slug' <<<"$API_BODY")" == "$slug" ]] && ok "$slug: site records template $slug@$(jq -r '.data.compose.template.version' <<<"$API_BODY")" || bad "$slug: template metadata missing: $(jq -c .data.compose <<<"$API_BODY")"

    status=""
    [[ -n $deployment ]] && status=$(wait_deployment "$deployment")
    if [[ $status != succeeded ]]; then
        # The first deployment is triggered while the site's targets are still preparing: it must wait for them
        # (status `waiting`) rather than fail. Redeploy anyway so the remaining checks still run.
        [[ -n $status ]] && bad "$slug: first deployment ended '$status' ($(jq -r '.data.error // ""' <<<"$API_BODY" | head -c 120)); redeploying"
        wait_targets_ready "$site" || return 1
        api POST "/sites/$site/deployments" '{}'
        [[ $API_CODE == 201 ]] || { bad "$slug: POST deployments -> $API_CODE: $API_BODY"; return 1; }
        status=$(wait_deployment "$(jq -r .data.id <<<"$API_BODY")")
    fi
    [[ $status == succeeded ]] && ok "$slug: deployment succeeded (compose up --wait + health check through the edge)" || {
        bad "$slug: deployment ended '$status': $(jq -c '.data | {phase, error}' <<<"$API_BODY")"; return 1; }

    local body="" deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do body=$(site_get srv-app-2 "$host" "$probe"); grep -qiE "$check" <<<"$body" && break; sleep 5; done
    grep -qiE "$check" <<<"$body" && ok "$slug: public URL https://$host$probe answers through the edge" || bad "$slug: https://$host$probe -> ${body:0:200}"
}

stage_templates() {
    step "templates: deploy catalog templates (Uptime Kuma, Umami) through the API"
    deploy_template uptime-kuma kuma '{"TIMEZONE":"UTC"}' / 'uptime kuma' || true
    deploy_template umami umami '{}' /api/heartbeat '"ok": ?true' || true
}

for s in $STAGES; do "stage_$s"; done

printf '\n==> e2e-deploy: %d passed, %d failed\n' "$pass" "$fail"
(( fail == 0 ))
