#!/usr/bin/env bash
# Full product E2E on the local simulation, driven ONLY through public surfaces:
#   `artisan kiln:admin` (first-run bootstrap) → REST API /api/v1 → the install command each server prints.
#
#   ./e2e-deploy.sh                          run every stage
#   ONLY=deploy,release ./e2e-deploy.sh      run selected stages (comma- or space-separated; alias: STAGES)
#   SKIP=templates ./e2e-deploy.sh           run every stage except these
#   FAST=1 ./e2e-deploy.sh                   skip the stages that only pull third-party images (templates)
#
# Requires `make up`. State for later stages is kept in .data/e2e.env, so a stage can be re-run on its own
# after a full run. Each stage prints its duration; a summary table (and .data/e2e-timings.tsv) ends the run.
# Remote shell snippets are single-quoted on purpose (SC2016); SERVER_*/SITE_*/… come from .data/e2e.env (SC2154).
# shellcheck disable=SC2016,SC2154
set -uo pipefail
cd "$(dirname "$0")" || exit 1
set -a
# shellcheck source=/dev/null
. ./sim.env
# shellcheck source=/dev/null
. ./.data/secrets.env
set +a

EDGE="https://kiln.test:${SIM_EDGE_HTTPS_PORT}"
CURL=(curl -sS --cacert .data/edge-root.crt --resolve "kiln.test:${SIM_EDGE_HTTPS_PORT}:127.0.0.1")
STATE=.data/e2e.env
ALL_STAGES="bootstrap servers sites deploy release rollback failure octane bun release_env redis waiting observability compose compose_redeploy compose_failure templates"
STAGES="${ONLY:-${STAGES:-$ALL_STAGES}}"
STAGES="${STAGES//,/ }"
SKIP="${SKIP:-}"
if [[ ${FAST:-0} == 1 ]]; then SKIP="$SKIP templates"; fi
POLL=${POLL:-1}   # seconds between status polls (deployments, targets, servers)

pass=0 fail=0 API_CODE=000 API_BODY='' KILN_TOKEN=${KILN_TOKEN:-}
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$*"; pass=$((pass + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$*"; fail=$((fail + 1)); }
step() { printf '\n\033[1m== %s\033[0m  \033[2m(%s)\033[0m\n' "$*" "$(date +%H:%M:%S)"; }
save() { printf -v "$1" '%s' "$2"; grep -v "^$1=" "$STATE" 2>/dev/null > "$STATE.tmp"; printf '%s=%q\n' "$1" "$2" >> "$STATE.tmp"; mv "$STATE.tmp" "$STATE"; }
# shellcheck source=/dev/null
if [[ -f $STATE ]]; then . "$STATE"; fi
if [[ ! -s .data/edge-root.crt ]]; then make -s ca >/dev/null; fi

# Run a command in a sim container. `docker exec` on the compose container name: same effect as
# `docker compose exec -T`, without re-parsing the compose project on each of the few hundred calls.
sx() { local svc=$1; shift; docker exec "kiln-sim-${svc}-1" "$@"; }

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
    json=$(sx control-plane php artisan kiln:admin admin@kiln.test --organization="Kiln E2E" --token="e2e-$(date +%s)" --json 2>/dev/null | tail -1)
    KILN_TOKEN=$(jq -r '.token // empty' <<<"$json")
    if [[ -n $KILN_TOKEN ]]; then
        save KILN_TOKEN "$KILN_TOKEN"; save KILN_ORG "$(jq -r .organization_id <<<"$json")"
        ok "admin@kiln.test owns organization $(jq -r .organization <<<"$json")"
    else
        bad "kiln:admin did not return a token: $json"; return 1
    fi

    api GET /me
    if [[ $API_CODE == 200 ]]; then ok "GET /api/v1/me -> 200 ($(jq -r '.data.email // .data.user.email // "?"' <<<"$API_BODY"))"; else bad "GET /api/v1/me -> $API_CODE: $API_BODY"; fi
}

server_status() { api GET "/servers/$1"; jq -r '.data.status // empty' <<<"$API_BODY"; }

provision_server() { # provision_server NAME CONTAINER TYPE STACK_JSON
    local name=$1 container=$2 type=$3 stack=$4 body id cmd
    api POST /servers "{\"name\":\"$name\",\"type\":\"$type\",\"provider\":\"custom\",\"stack\":$stack}"; body=$API_BODY
    if [[ $API_CODE != 201 ]]; then bad "POST /servers $name -> $API_CODE: $body"; return 1; fi
    id=$(jq -r .data.id <<<"$body"); cmd=$(jq -r .data.install_command <<<"$body")
    save "SERVER_${container//-/_}" "$id"
    ok "created custom $type server $name ($id)"

    if [[ -n $cmd && $cmd != null ]]; then ok "$name: API returned an install command"; else bad "$name: no install command"; return 1; fi
    if sx "$container" bash -c "$cmd" > ".data/install-$container.log" 2>&1; then
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
            sleep 2
        done
        if [[ $s == active ]]; then ok "$c provisioned -> active"; else bad "$c provisioning ended in '${s:-unknown}'"; fi
    done

    for c in srv-app-1 srv-app-2; do
        if sx "$c" bash -c 'command -v frankenphp >/dev/null && frankenphp version' >/dev/null 2>&1; then ok "$c: frankenphp installed"; else bad "$c: frankenphp missing"; fi
        if sx "$c" php8.4 -v >/dev/null 2>&1 || sx "$c" php -v >/dev/null 2>&1; then ok "$c: php CLI installed"; else bad "$c: php CLI missing"; fi
        if sx "$c" node --version >/dev/null 2>&1; then ok "$c: node installed"; else bad "$c: node missing"; fi
    done
    if sx srv-db-1 bash -c 'systemctl is-active postgresql' 2>/dev/null | grep -q active; then ok "srv-db-1: postgresql running"; else bad "srv-db-1: postgresql not running"; fi
    for c in srv-app-1 srv-app-2 srv-db-1; do
        if [[ "$(sx "$c" bash -c 'exec 3<>/dev/tcp/127.0.0.1/22; head -c 7 <&3' 2>/dev/null)" == SSH-2.0 ]]; then ok "$c: SSH still answering on :22 after provisioning"; else bad "$c: SSH not answering after provisioning"; fi
    done
    for c in srv-app-1 srv-app-2 srv-db-1; do
        if sx "$c" bash -c 'nft list ruleset 2>/dev/null | grep -q "dport 22"'; then ok "$c: nftables firewall applied"; else bad "$c: firewall ruleset missing"; fi
    done
    # The sim's agents run the very build the control plane serves: reported as current, and an upgrade is a no-op.
    if [[ -n ${SERVER_srv_app_1:-} ]]; then
        api GET "/servers/$SERVER_srv_app_1"
        if jq -e '.data.agent.version != null and .data.agent.available_version != null and .data.agent.update_available == false' >/dev/null 2>&1 <<<"$API_BODY"; then
            ok "srv-app-1 runs the shipped agent build ($(jq -r .data.agent.version <<<"$API_BODY"))"
        else bad "agent version report: $(jq -c .data.agent <<<"$API_BODY")"; fi
        api POST "/servers/$SERVER_srv_app_1/agent/upgrade" '{}'
        if [[ $API_CODE == 409 ]] && jq -e '.message | test("already runs")' >/dev/null 2>&1 <<<"$API_BODY"; then ok "agent upgrade API: nothing to do for a current agent"; else bad "agent upgrade API -> $API_CODE: $API_BODY"; fi
    fi
}

# ---------------------------------------------------------------------------------------------- sites/deploys
git_head() { sx sim-git git --git-dir="/srv/git/$1.git" rev-parse main 2>/dev/null | tr -d '\r'; }

git_commit() { # git_commit REPO MESSAGE SHELL_SNIPPET  -> new commit on main in the sim git server
    sx sim-git sh -c "set -e; rm -rf /tmp/w; git clone -q /srv/git/$1.git /tmp/w; cd /tmp/w; $3; git add -A; git commit -q -m '$2'; git push -q origin main" >/dev/null 2>&1
}

deployment_status() { api GET "/deployments/$1"; jq -r '.data.status // empty' <<<"$API_BODY"; }

wait_targets_ready() { # wait_targets_ready SITE_ID  (site targets finish preparing, e.g. runtime install)
    local deadline=$((SECONDS + 1900)) states=""
    while (( SECONDS < deadline )); do
        api GET "/sites/$1"
        states=$(jq -r '[.data.targets[].status] | unique | join(",")' <<<"$API_BODY")
        [[ $states == ready ]] && return 0
        [[ $states == *failed* ]] && break
        sleep "$POLL"
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
        sleep "$POLL"
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
    sx "$1" curl -sSk -L -m 15 --resolve "$2:443:127.0.0.1" --resolve "$2:80:127.0.0.1" "https://$2$3" 2>/dev/null
}

stage_sites() {
    step "sites: custom git connection + multi-server Laravel site"
    api POST /source-control/connections '{"provider":"custom","auth_type":"none","name":"sim git"}'
    if [[ $API_CODE == 201 ]]; then save GIT_CONNECTION "$(jq -r .data.id <<<"$API_BODY")"; ok "custom git connection created"; else bad "connection -> $API_CODE: $API_BODY"; return 1; fi

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
        if [[ $API_CODE == 200 ]]; then ok "site environment updated via API (version $(jq -r '.data.version // "?"' <<<"$API_BODY"))"; else bad "PUT env -> $API_CODE: $API_BODY"; fi
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
    if [[ $migrate == app-1 ]]; then ok "migrations ran on the leader only ($migrate)"; else bad "migrations ran on: '${migrate:-none}' (expected app-1)"; fi

    for c in srv-app-1 srv-app-2; do
        local body; body=$(site_get "$c" "$SHOP_HOST" /)
        local rel; rel=$(jq -r '.release // empty' 2>/dev/null <<<"$body")
        local want; want=$(basename "$(sx "$c" bash -c 'readlink /srv/kiln/sites/shop/current' | tr -d '\r')")
        if [[ -n $rel && $rel == "$want" ]]; then ok "$c serves the Laravel app from the active release ($rel)"; else bad "$c: GET / -> ${body:0:200} (current: $want)"; fi
        [[ $c == srv-app-1 ]] && save SHOP_RELEASE_1 "$rel"
        if [[ "$(site_get "$c" "$SHOP_HOST" /health)" == ok ]]; then ok "$c: /health ok"; else bad "$c: /health failed"; fi
        if sx "$c" bash -c 'readlink /srv/kiln/sites/*/current' >/dev/null 2>&1; then ok "$c: current -> $(sx "$c" bash -c 'basename $(readlink /srv/kiln/sites/*/current)' | tr -d '\r')"; else bad "$c: no current symlink"; fi
    done
}

stage_release() {
    step "release: second commit deploys zero-downtime; releases list shows it active"
    if git_commit laravel-demo "Second release" "sed -i 's/Kiln Demo/Kiln Demo v2/' .env.example && sed -i \"s/'app' => config('app.name')/'app' => 'Kiln Demo v2'/\" routes/web.php"; then ok "pushed a second commit"; else bad "could not push second commit"; return 1; fi
    local commit; commit=$(git_head laravel-demo); save SHOP_COMMIT_2 "$commit"
    deploy_and_wait "$SITE_SHOP" "$commit" "second deploy" succeeded || return 1
    for c in srv-app-1 srv-app-2; do
        if [[ "$(site_get "$c" "$SHOP_HOST" / | jq -r .app 2>/dev/null)" == "Kiln Demo v2" ]]; then ok "$c serves v2"; else bad "$c does not serve v2"; fi
    done
    api GET "/sites/$SITE_SHOP/releases"
    if [[ "$(jq -r '.data[0].commit' <<<"$API_BODY")" == "$commit" && "$(jq -r '.data[0].active' <<<"$API_BODY")" == true ]]; then ok "releases: newest is active ($(jq -r '.data | length' <<<"$API_BODY") retained)"; else bad "releases: $API_BODY"; fi
}

stage_rollback() {
    step "rollback: back to release 1 on every server"
    api POST "/sites/$SITE_SHOP/rollback" '{}'
    [[ $API_CODE == 201 ]] || { bad "rollback -> $API_CODE: $API_BODY"; return 1; }
    local id s="" deadline=$((SECONDS + 600)); id=$(jq -r .data.id <<<"$API_BODY")
    while (( SECONDS < deadline )); do s=$(deployment_status "$id"); [[ $s == succeeded || $s == failed ]] && break; sleep "$POLL"; done
    if [[ $s == succeeded ]]; then ok "rollback deployment succeeded"; else bad "rollback ended '$s'"; fi
    for c in srv-app-1 srv-app-2; do
        local rel; rel=$(site_get "$c" "$SHOP_HOST" / | jq -r '.release // empty' 2>/dev/null)
        if [[ -n $rel && $rel == "$SHOP_RELEASE_1" ]]; then ok "$c serves release 1 again ($rel)"; else bad "$c not rolled back (serves ${rel:-nothing}, want $SHOP_RELEASE_1)"; fi
    done
}

stage_failure() {
    step "failure: a release whose /health returns 500 is rolled back automatically"
    if git_commit laravel-demo "Broken health" "sed -i \"s#Route::get('/health', fn () => response('ok'));#Route::get('/health', fn () => response('broken', 500));#\" routes/web.php"; then ok "pushed a broken commit"; else bad "could not push broken commit"; return 1; fi
    local before; before=$(site_get srv-app-1 "$SHOP_HOST" / | jq -r .release 2>/dev/null)
    deploy_and_wait "$SITE_SHOP" "$(git_head laravel-demo)" "broken deploy" failed
    if [[ "$(jq -r .data.rolled_back <<<"$API_BODY")" == true ]]; then ok "deployment reports rolled_back=true"; else bad "rolled_back not set: $(jq -c '.data | {status,phase,error}' <<<"$API_BODY")"; fi
    for c in srv-app-1 srv-app-2; do
        local now; now=$(site_get "$c" "$SHOP_HOST" / | jq -r .release 2>/dev/null)
        if [[ "$(site_get "$c" "$SHOP_HOST" /health)" == ok && $now == "$before" ]]; then ok "$c still serves the previous healthy release"; else bad "$c: release=$now health=$(site_get "$c" "$SHOP_HOST" /health)"; fi
    done
}

# ---------------------------------------------------------------------------------------------- octane
octane_get() { site_get srv-app-1 "$OCTANE_HOST" "$1"; }

# FrankenPHP runs Octane workers as threads of one process (2 x CPUs by default), and requests spread across
# them: a handful of samples can all land on different workers. Probe with more requests than workers, in one
# exec on the server, and decide the mode by the app's LARAVEL_OCTANE flag.
OCTANE_SAMPLES=48

octane_probe() { # -> "<any response under Octane: true|false> <highest per-worker request counter>"
    sx srv-app-1 bash -c "for _ in \$(seq 1 $OCTANE_SAMPLES); do curl -sk -m 15 --resolve '$OCTANE_HOST:443:127.0.0.1' 'https://$OCTANE_HOST/octane'; echo; done" 2>/dev/null \
        | jq -rs '[.[] | objects] | "\(map(.octane == true) | any) \(map(.served // 0) | max // 0)"' 2>/dev/null
}

octane_wait_mode() { # octane_wait_mode on|off SECONDS -> 0 when the edge serves /octane in that mode
    local want=$1 deadline=$((SECONDS + $2)) probe flag served
    while (( SECONDS < deadline )); do
        probe=$(octane_probe); flag=${probe% *}; served=${probe#* }
        # on: answered by Octane, and some long-lived worker served more than one request.
        if [[ $want == on && $flag == true && ${served:-0} -gt 1 ]]; then return 0; fi
        # off: classic FrankenPHP (no Octane flag, every request starts fresh).
        if [[ $want == off && $flag == false && ${served:-0} -eq 1 ]]; then return 0; fi
        sleep 1
    done
    return 1
}

octane_load_start() { # background request loop on srv-app-1 through the edge; one HTTP status per line
    sx srv-app-1 bash -c 'rm -f /tmp/octane-codes /tmp/octane-stop' >/dev/null 2>&1
    docker exec -d kiln-sim-srv-app-1-1 bash -c "while [ ! -f /tmp/octane-stop ]; do curl -sk -o /dev/null -w '%{http_code}\n' -m 60 --resolve '$OCTANE_HOST:443:127.0.0.1' 'https://$OCTANE_HOST/octane' >> /tmp/octane-codes; sleep 0.1; done"
}

octane_load_stop() { # -> "TOTAL FAILED" (non-200 answers, including curl errors = 000)
    sx srv-app-1 bash -c 'touch /tmp/octane-stop; sleep 3; total=$(wc -l < /tmp/octane-codes); failed=$(grep -vc "^200$" /tmp/octane-codes); echo "$total $failed"; grep -v "^200$" /tmp/octane-codes | sort | uniq -c | head -5 >&2' | tr -d '\r'
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
    if [[ $API_CODE == 200 ]]; then ok "environment set"; else bad "PUT env -> $API_CODE: $API_BODY"; fi

    # Octane on before the first deploy: the program cannot start on the placeholder release, and the edge must
    # keep serving the placeholder directly (never proxy to a port nothing listens on).
    wait_targets_ready "$SITE_OCTANE" || return 1
    api PUT "/sites/$SITE_OCTANE/laravel" '{"octane":true}'
    [[ $API_CODE == 200 ]] || { bad "PUT laravel octane=true -> $API_CODE: $API_BODY"; return 1; }
    local port server; port=$(jq -r .data.octane_port <<<"$API_BODY"); server=$(jq -r .data.octane_server <<<"$API_BODY")
    save OCTANE_PORT "$port"
    if [[ $server == frankenphp && $port =~ ^[0-9]+$ ]]; then ok "octane enabled via API: server $server, port $port"; else bad "octane settings: $API_BODY"; fi
    sleep 15
    local body; body=$(octane_get /)
    if [[ $body == *"not been deployed yet"* ]]; then ok "never-deployed site still serves the placeholder through the edge"; else bad "placeholder not served: ${body:0:200}"; fi

    # Healthy commit (the failure stage may have left a broken /health on main).
    if git_commit laravel-demo "Octane v1" "sed -i \"s#response('broken', 500)#response('ok')#\" routes/web.php && sed -i \"s/'app' => config('app.name')/'app' => 'Kiln Octane v1'/; s/'app' => 'Kiln Demo v2'/'app' => 'Kiln Octane v1'/\" routes/web.php"; then ok "pushed Octane v1"; else bad "could not push Octane v1"; return 1; fi
    deploy_and_wait "$SITE_OCTANE" "$(git_head laravel-demo)" "octane first deploy" succeeded || return 1

    if octane_wait_mode on 240; then ok "the edge proxies to Octane: /octane answers from long-lived workers (octane, max served: $(octane_probe))"
    else bad "edge not serving from Octane workers: $(octane_get /octane)"; fi
    if sx srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then ok "octane listens on 127.0.0.1:$port"; else bad "nothing listens on 127.0.0.1:$port"; fi
    if sx srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$((port + 10000))" 2>/dev/null; then ok "octane's FrankenPHP admin API on its own port $((port + 10000)) (edge keeps :2019)"; else bad "no octane admin port $((port + 10000))"; fi
    if [[ "$(octane_get /)" == *'"Kiln Octane v1"'* ]]; then ok "serves release v1 through Octane"; else bad "GET / -> $(octane_get /)"; fi
    if [[ "$(octane_get /kiln-static.txt)" == "static.txt served by Caddy" ]]; then ok "public/ files are served directly"; else bad "static file -> $(octane_get /kiln-static.txt | head -c 120)"; fi
    if [[ "$(octane_get /frankenphp-worker.php)" != *"<?php"* ]]; then ok "PHP sources in public/ are never served as files"; else bad "frankenphp-worker.php source exposed"; fi
    if [[ "$(octane_get /health)" == ok ]]; then ok "/health ok through Octane"; else bad "/health -> $(octane_get /health)"; fi

    # Redeploy under load: Octane restarts on the new release while the edge holds requests -> no failed request.
    if git_commit laravel-demo "Octane v2" "sed -i \"s/'app' => 'Kiln Octane v1'/'app' => 'Kiln Octane v2'/\" routes/web.php"; then ok "pushed Octane v2"; else bad "could not push Octane v2"; return 1; fi
    octane_load_start
    sleep 3
    deploy_and_wait "$SITE_OCTANE" "$(git_head laravel-demo)" "octane redeploy under load" succeeded
    sleep 5
    local result total failed; result=$(octane_load_stop); total=${result% *}; failed=${result#* }
    if (( ${total:-0} > 20 && ${failed:-1} == 0 )); then ok "zero failed requests during the redeploy ($total requests)"; else bad "requests during the redeploy: $total total, $failed failed"; fi
    if [[ "$(octane_get /)" == *'"Kiln Octane v2"'* ]]; then ok "serves release v2 (Octane restarted on the new release)"; else bad "not serving v2: $(octane_get /)"; fi
    if octane_wait_mode on 60; then ok "still served by Octane workers after the redeploy"; else bad "not in worker mode after the redeploy: $(octane_get /octane)"; fi

    # Switch off under load: the edge goes back to FrankenPHP first, then the program stops.
    octane_load_start
    sleep 2
    api PUT "/sites/$SITE_OCTANE/laravel" '{"octane":false}'
    if [[ $API_CODE == 200 ]]; then ok "octane disabled via API"; else bad "PUT laravel octane=false -> $API_CODE: $API_BODY"; fi
    if octane_wait_mode off 180; then ok "the edge serves the site directly again (classic FrankenPHP, served=1)"; else bad "still proxied after disabling: $(octane_get /octane)"; fi
    local stopped=false deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do
        sx srv-app-1 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null || { stopped=true; break; }
        sleep 1
    done
    if [[ $stopped == true ]]; then ok "the octane program stopped after the edge switched back"; else bad "octane still listening on $port"; fi
    result=$(octane_load_stop); total=${result% *}; failed=${result#* }
    if (( ${total:-0} > 10 && ${failed:-1} == 0 )); then ok "zero failed requests while switching Octane off ($total requests)"; else bad "requests while switching off: $total total, $failed failed"; fi
    if [[ "$(octane_get /)" == *'"Kiln Octane v2"'* && "$(octane_get /health)" == ok ]]; then ok "the app still serves v2 without Octane"; else bad "after disabling: $(octane_get /)"; fi
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
    if jq -e '.app == "kiln-bun-demo"' >/dev/null 2>&1 <<<"$body"; then ok "srv-app-2 serves the Bun app ($body)"; else bad "bun GET / -> ${body:0:200}"; fi
}

proc_env() { # proc_env CONTAINER PATTERN VAR -> VAR from the process environment (/proc/<pid>/environ) of the newest match
    sx "$1" bash -c "pid=\$(pgrep -nf '$2') && tr '\\0' '\\n' < /proc/\$pid/environ | sed -n 's/^$3=//p'" 2>/dev/null | tr -d '\r'
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
    if [[ $API_CODE == 200 ]]; then ok "site variable KILN_E2E_GREETING set"; else bad "PUT env -> $API_CODE: $API_BODY"; fi

    for round in 1 2; do
        if git_commit bun-demo "Release env round $round" "echo '// round $round' >> src/index.ts"; then ok "pushed bun commit $round"; else bad "could not push bun commit"; return 1; fi
        deploy_and_wait "$SITE_API" "$(git_head bun-demo)" "bun deploy (env round $round)" succeeded || return 1
        local want deployment body proc_rel proc_dep proc_greet deadline=$((SECONDS + 60))
        want=$(active_release "$SITE_API")
        api GET "/sites/$SITE_API/releases"; deployment=$(jq -r '[.data[] | select(.active)][0].deployment_id // empty | ascii_upcase' <<<"$API_BODY")
        while (( SECONDS < deadline )); do body=$(site_get srv-app-2 "$API_HOST" /); [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$want" ]] && break; sleep 1; done
        if [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$want" && "$(jq -r .deployment <<<"$body" 2>/dev/null)" == "$deployment" ]]; then ok "GET / reports KILN_RELEASE_ID=$want KILN_DEPLOYMENT_ID=$deployment (the active release)"; else bad "GET / -> ${body:0:300} (want release $want, deployment $deployment)"; fi
        if [[ "$(jq -r .greeting <<<"$body" 2>/dev/null)" == hello-from-env ]]; then ok "the site variable reaches the app"; else bad "greeting: $(jq -r .greeting <<<"$body" 2>/dev/null)"; fi
        # Straight from the supervised process (not a .env the runtime loaded): what proc.apply put into its env.
        proc_rel=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_RELEASE_ID)
        proc_dep=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_DEPLOYMENT_ID)
        proc_greet=$(proc_env srv-app-2 '[s]rc/index.ts' KILN_E2E_GREETING)
        if [[ $proc_rel == "$want" && $proc_dep == "$deployment" && $proc_greet == hello-from-env ]]; then ok "api.app process env: KILN_RELEASE_ID=$proc_rel KILN_DEPLOYMENT_ID=$proc_dep KILN_E2E_GREETING=$proc_greet"
        else bad "api.app process env: release='$proc_rel' deployment='$proc_dep' greeting='$proc_greet' (want $want / $deployment)"; fi
    done
}

# ---------------------------------------------------------------------------------------------- redis
stage_redis() {
    step "redis: install Redis on app-2, create an instance through the API, reference it from the Bun site, deploy, connect"
    api POST "/servers/$SERVER_srv_app_2/database-engine" '{"engine":"redis"}'
    if [[ $API_CODE == 202 ]]; then ok "Redis install requested on app-2"
    elif [[ $API_CODE == 422 ]] && grep -q 'already runs' <<<"$API_BODY"; then ok "app-2 already runs Redis"
    else bad "POST database-engine -> $API_CODE: $API_BODY"; return 1; fi

    api GET /projects
    local project; project=$(jq -r '[.data[] | select(.is_default)][0].id // empty' <<<"$API_BODY")
    [[ -n $project ]] || { bad "no default project: $API_BODY"; return 1; }
    # The engine registers once the plan converged; until then the server "does not run Redis".
    local deadline=$((SECONDS + 600)) created=""
    while (( SECONDS < deadline )); do
        api POST "/projects/$project/environments/production/services" \
            "{\"kind\":\"database\",\"engine\":\"redis\",\"server_id\":\"$SERVER_srv_app_2\",\"name\":\"cache\",\"maxmemory_mb\":64,\"eviction\":\"allkeys-lru\"}"
        if [[ $API_CODE == 201 ]]; then created=new; break; fi
        if [[ $API_CODE == 422 ]] && grep -qi 'already' <<<"$API_BODY"; then created=existing; break; fi
        sleep 3
    done
    if [[ -n $created ]]; then ok "Redis service 'cache' ($created) on app-2"; else bad "create Redis service -> $API_CODE: $API_BODY"; return 1; fi

    local unit=redis-server@kiln-cache.service
    deadline=$((SECONDS + 180))
    while (( SECONDS < deadline )); do sx srv-app-2 systemctl is-active --quiet "$unit" && break; sleep 2; done
    if sx srv-app-2 systemctl is-active --quiet "$unit"; then ok "$unit running"; else bad "$unit not running: $(sx srv-app-2 journalctl -u "$unit" -n 20 --no-pager 2>&1 | tail -5)"; return 1; fi
    if [[ "$(sx srv-app-2 stat -c '%U %a' /var/lib/kiln-redis/cache | tr -d '\r')" == "kiln-redis-cache 700" ]]; then ok "data dir is the instance user's, 0700"; else bad "data dir: $(sx srv-app-2 stat -c '%U %a' /var/lib/kiln-redis/cache)"; fi
    if [[ "$(sx srv-app-2 stat -c '%U:%G %a' /etc/redis/redis-kiln-cache.conf | tr -d '\r')" == "root:kiln-redis-cache 640" ]]; then ok "config 0640 root:kiln-redis-cache"; else bad "config: $(sx srv-app-2 stat -c '%U:%G %a' /etc/redis/redis-kiln-cache.conf)"; fi
    if sx srv-app-2 bash -c "ps -eo user:32,args | grep -q '^kiln-redis-cache .*redis-server'"; then ok "redis-server runs as kiln-redis-cache"; else bad "instance user: $(sx srv-app-2 ps -eo user:32,args | grep redis-server)"; fi
    # The stock instance (no password) can't point itself at the instance's data.
    if sx srv-app-2 redis-cli -p 6379 CONFIG SET dir /var/lib/kiln-redis/cache 2>&1 | grep -q '^ERR'; then ok "stock 6379 cannot reach the instance's data"; else bad "stock 6379 could CONFIG SET dir into the instance"; fi

    api GET "/sites/$SITE_API/env"
    local env; env=$(jq -r '.data.content' <<<"$API_BODY" | grep -vE '^REDIS_(URL|PORT|PASSWORD)=')
    env+=$'\nREDIS_URL=${{ cache.REDIS_URL }}\nREDIS_PORT=${{ cache.REDIS_PORT }}\nREDIS_PASSWORD=${{ cache.REDIS_PASSWORD }}\n'
    api PUT "/sites/$SITE_API/env" "$(jq -n --arg c "$env" '{content: $c}')"
    if [[ $API_CODE == 200 ]]; then ok "Bun site references cache.REDIS_*"; else bad "PUT env -> $API_CODE: $API_BODY"; return 1; fi
    deploy_and_wait "$SITE_API" "$(git_head bun-demo)" "bun deploy with Redis references" succeeded || return 1

    local url port pw
    url=$(proc_env srv-app-2 '[s]rc/index.ts' REDIS_URL); port=$(proc_env srv-app-2 '[s]rc/index.ts' REDIS_PORT); pw=$(proc_env srv-app-2 '[s]rc/index.ts' REDIS_PASSWORD)
    if [[ $url =~ ^redis://default:[A-Za-z0-9]+@127\.0\.0\.1:${port}$ && $port -ge 6380 && $port -le 6479 ]]; then ok "process env: REDIS_URL redis://default:…@127.0.0.1:$port"; else bad "process env: REDIS_URL='${url//:*@/:…@}' REDIS_PORT='$port'"; return 1; fi
    if [[ "$(sx srv-app-2 env REDISCLI_AUTH="$pw" redis-cli -p "$port" SET kiln-e2e ok | tr -d '\r')" == OK && "$(sx srv-app-2 env REDISCLI_AUTH="$pw" redis-cli -p "$port" GET kiln-e2e | tr -d '\r')" == ok ]]; then ok "the referenced password works on the instance"; else bad "SET/GET with the referenced password failed"; fi
    if sx srv-app-2 env REDISCLI_AUTH="$pw" redis-cli -p "$port" CONFIG GET dir 2>&1 | grep -q '^ERR'; then ok "CONFIG is not available to clients"; else bad "CONFIG still works for clients"; fi
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
            if [[ $(jq -r .data.id <<<"$API_BODY") == "$id" ]]; then ok "a second trigger coalesced into the waiting deployment"; else bad "second trigger created another deployment: $API_BODY"; fi
        else
            ok "the server finished preparing before the second trigger (not coalesced)"
        fi
    elif [[ $status == building || $status == deploying ]]; then
        ok "the server was already prepared when the deployment was triggered ($status); waiting not exercised"
    else
        bad "deployment triggered during preparation ended up '$status': $(jq -c '.data | {status, error}' <<<"$API_BODY")"; return 1
    fi

    status=$(wait_deployment "$id")
    if [[ $status == succeeded ]]; then ok "the waiting deployment started by itself and succeeded"; else
        bad "early deployment ended '$status': $(jq -c '.data | {phase, error}' <<<"$API_BODY")"
        api GET "/deployments/$id/output?after=-1"; jq -r '.data[-25:][] | "      [\(.server // "-")/\(.phase // "-")] \(.data | rtrimstr("\n"))"' <<<"$API_BODY" 2>/dev/null
        return 1
    fi
    api GET "/deployments/$id/output?after=-1"
    if jq -e '[.data[].data] | any(test("finish preparing"))' >/dev/null 2>&1 <<<"$API_BODY"; then ok "its log explains the wait"; else ok "no wait recorded in its log (the server was ready)"; fi
    local body; body=$(site_get srv-app-1 "$EARLY_HOST" /)
    if [[ "$(jq -r .release <<<"$body" 2>/dev/null)" == "$(active_release "$SITE_EARLY")" ]]; then ok "srv-app-1 serves early from its active release"; else bad "early GET / -> ${body:0:200}"; fi
}

stage_observability() {
    step "observability: APM traces, exceptions -> Insights issue, deployment events"
    for path in / /work /slow /outgoing /boom /boom; do site_get srv-app-1 "$SHOP_HOST" "$path" >/dev/null; done
    ok "generated traffic on srv-app-1 (requests, job, slow route, outgoing HTTP, 2 exceptions)"
    # Telemetry is batched (agent OTLP export, Insights ingestion): poll each signal instead of a fixed wait.
    local traces=0 issues=0 deploys=0 deadline=$((SECONDS + 90))
    while (( SECONDS < deadline )); do
        traces=$(curl -sS "http://127.0.0.1:${KILN_TEMPO_PORT}/api/search?tags=kiln.event.type%3Drequest&limit=20" | jq '.traces | length' 2>/dev/null)
        (( ${traces:-0} > 0 )) && break
        sleep 2
    done
    if (( ${traces:-0} > 0 )); then ok "Tempo has $traces request traces from kiln/apm-laravel"; else bad "no request traces in Tempo"; fi
    while (( SECONDS < deadline )); do
        issues=$(sx control-plane php artisan tinker --execute='echo DB::table("insights_issues")->where("title","like","%Kiln E2E demo exception%")->count();' 2>/dev/null | tail -1 | tr -d '\r')
        [[ ${issues:-0} -ge 1 ]] && break
        sleep 2
    done
    if [[ ${issues:-0} -ge 1 ]]; then ok "Insights grouped the exception into an issue ($issues)"; else bad "no Insights issue for the demo exception"; fi
    while (( SECONDS < deadline )); do
        deploys=$(curl -sS -G "http://127.0.0.1:${KILN_LOKI_PORT}/loki/api/v1/query_range" --data-urlencode 'query={service_name="kiln-agent"} | kiln_event_type="deployment"' --data-urlencode "start=$(( $(date +%s) - 7200 ))000000000" | jq '[.data.result[].values[]] | length' 2>/dev/null)
        (( ${deploys:-0} > 0 )) && break
        sleep 2
    done
    if (( ${deploys:-0} > 0 )); then ok "Loki has $deploys deployment lifecycle events from the agents"; else bad "no deployment events in Loki"; fi
    # Per-site logs of the web requests themselves (FrankenPHP): the edge's access log and the app's log files.
    local access=0 applog=0
    while (( SECONDS < deadline )); do
        access=$(loki_count '{service_name="shop", kiln_log_kind="access"} | url_path="/boom" | http_response_status_code="500"')
        (( ${access:-0} > 0 )) && break
        sleep 2
    done
    if (( ${access:-0} > 0 )); then ok "edge access log of shop reached Loki (kiln_log_kind=access, $access x GET /boom 500)"; else bad "no access log records for shop in Loki"; fi
    while (( SECONDS < deadline )); do
        applog=$(loki_count '{service_name="shop", kiln_log_kind="app"} |= "Kiln E2E demo exception" |= "#0 "')
        (( ${applog:-0} > 0 )) && break
        sleep 2
    done
    if (( ${applog:-0} > 0 )); then ok "shop's storage/logs reached Loki with the stack trace merged into the error record ($applog)"; else bad "no merged Laravel error record for shop in Loki"; fi
    api GET "/sites/$SITE_SHOP/access-logs?since=3600&path=/boom&status=5xx"
    if [[ $API_CODE == 200 ]] && jq -e '.data | length > 0 and (.[0].status == 500) and (.[0].method == "GET")' >/dev/null 2>&1 <<<"$API_BODY"; then ok "GET /sites/{id}/access-logs returns the requests"; else bad "access-logs API -> $API_CODE: ${API_BODY:0:300}"; fi
}

loki_count() { # loki_count LOGQL -> number of lines in the last hour
    curl -sS -G "http://127.0.0.1:${KILN_LOKI_PORT}/loki/api/v1/query_range" --data-urlencode "query=$1" \
        --data-urlencode "start=$(( $(date +%s) - 3600 ))000000000" | jq '[.data.result[].values[]] | length' 2>/dev/null
}

# ---------------------------------------------------------------------------------------------- compose
compose_get() { site_get srv-app-2 "$COMPOSE_HOST" "$1"; }

stage_compose() {
    step "compose: repo compose site (build: service via kiln-builder docker mode + redis volume) through the API"
    if sx srv-app-2 bash -c 'systemctl is-active docker' 2>/dev/null | grep -q active; then ok "srv-app-2: docker running"; else bad "srv-app-2: docker not running"; fi
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
    if jq -e '.app == "kiln-compose-demo" and .greeting == "hello"' >/dev/null 2>&1 <<<"$body"; then ok "public URL answers through the edge ($body)"; else bad "compose GET / -> ${body:0:200}"; return 1; fi
    if [[ "$(compose_get /health)" == ok ]]; then ok "/health ok through the edge"; else bad "compose /health failed"; fi

    local images; images=$(sx srv-app-2 bash -c 'for c in $(docker ps -q --filter label=kiln.site=compose-demo); do docker inspect "$c" --format "{{index .Config.Labels \"kiln.service\"}}={{.Config.Image}}"; done' 2>/dev/null | tr -d '\r' | sort | tr '\n' ' ')
    if [[ $images == *"app=sim-registry:5000/kiln/compose-demo/app@sha256:"* ]]; then ok "app runs the built image pinned by digest ($images)"; else bad "app image not digest-pinned: $images"; fi
    if sx srv-app-2 bash -c "docker port compose-demo-app-1 8080" 2>/dev/null | grep -q '^127.0.0.1:'; then ok "app published on loopback only"; else bad "app port not on 127.0.0.1: $(sx srv-app-2 docker port compose-demo-app-1 2>&1)"; fi

    local marker; marker="persist-$(date +%s)"
    save COMPOSE_MARKER "$marker"
    if [[ "$(compose_get "/set?value=$marker" | jq -r .marker 2>/dev/null)" == "$marker" ]]; then ok "stored $marker in redis"; else bad "could not store the marker in redis"; fi

    api GET "/sites/$SITE_COMPOSE"
    ok "site API: runtime $(jq -r .data.runtime <<<"$API_BODY")"

    local lines="" deadline=$((SECONDS + 90))
    while (( SECONDS < deadline )); do
        lines=$(curl -sS -G "http://127.0.0.1:${KILN_LOKI_PORT}/loki/api/v1/query_range" \
            --data-urlencode 'query={service_name="compose-demo"} | kiln_compose_service="app" |~ "kiln-compose-demo listening|GET /"' \
            --data-urlencode "start=$(( $(date +%s) - 3600 ))000000000" | jq '[.data.result[].values[]] | length' 2>/dev/null)
        (( ${lines:-0} > 0 )) && break
        sleep 2
    done
    if (( ${lines:-0} > 0 )); then ok "container logs reached Loki (service_name=compose-demo, kiln_compose_service=app: $lines lines)"; else bad "no compose container logs in Loki"; fi
}

stage_compose_redeploy() {
    step "compose: a new commit redeploys; the redis named volume keeps its data"
    if git_commit compose-demo "Greeting v2" "sed -i 's/APP_GREETING:-hello}/APP_GREETING:-hello v2}/' compose.yaml"; then ok "pushed a second compose commit"; else bad "could not push"; return 1; fi
    deploy_and_wait "$SITE_COMPOSE" "$(git_head compose-demo)" "compose redeploy" succeeded || return 1
    if [[ "$(compose_get / | jq -r .greeting 2>/dev/null)" == "hello v2" ]]; then ok "serves the new release (greeting v2)"; else bad "not serving v2: $(compose_get /)"; fi
    local got; got=$(compose_get /get | jq -r .marker 2>/dev/null)
    if [[ $got == "$COMPOSE_MARKER" ]]; then ok "redis volume data survived the redeploy ($got)"; else bad "redis data lost: got '$got', want $COMPOSE_MARKER"; fi
}

stage_compose_failure() {
    step "compose: a release whose container healthcheck fails is rolled back to the previous release"
    if git_commit compose-demo "Broken health" "sed -i 's/const HEALTHY = true;/const HEALTHY = false;/' app/server.js"; then ok "pushed a broken compose commit"; else bad "could not push"; return 1; fi
    deploy_and_wait "$SITE_COMPOSE" "$(git_head compose-demo)" "broken compose deploy" failed
    if [[ "$(jq -r .data.rolled_back <<<"$API_BODY")" == true ]]; then ok "deployment reports rolled_back=true ($(jq -r .data.error <<<"$API_BODY" | head -c 160))"; else bad "rolled_back not set: $(jq -c '.data | {status,phase,error}' <<<"$API_BODY")"; fi
    local deadline=$((SECONDS + 60)) health=""
    while (( SECONDS < deadline )); do health=$(compose_get /health); [[ $health == ok ]] && break; sleep 1; done
    if [[ $health == ok && "$(compose_get / | jq -r .greeting 2>/dev/null)" == "hello v2" ]]; then ok "the previous healthy release serves again"; else bad "after rollback: health=$health body=$(compose_get /)"; fi
    if [[ "$(compose_get /get | jq -r .marker 2>/dev/null)" == "$COMPOSE_MARKER" ]]; then ok "redis data intact after the rollback"; else bad "redis data lost after rollback"; fi
}

wait_deployment() { # wait_deployment ID -> final status (sets API_BODY)
    local s="" deadline=$((SECONDS + 1500))
    while (( SECONDS < deadline )); do
        s=$(deployment_status "$1")
        [[ $s == succeeded || $s == failed || $s == cancelled ]] && break
        sleep "$POLL"
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
    if [[ "$(jq -r '.data.compose.template.slug' <<<"$API_BODY")" == "$slug" ]]; then ok "$slug: site records template $slug@$(jq -r '.data.compose.template.version' <<<"$API_BODY")"; else bad "$slug: template metadata missing: $(jq -c .data.compose <<<"$API_BODY")"; fi

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
    if [[ $status == succeeded ]]; then ok "$slug: deployment succeeded (compose up --wait + health check through the edge)"; else
        bad "$slug: deployment ended '$status': $(jq -c '.data | {phase, error}' <<<"$API_BODY")"; return 1
    fi

    local body="" deadline=$((SECONDS + 120))
    while (( SECONDS < deadline )); do body=$(site_get srv-app-2 "$host" "$probe"); grep -qiE "$check" <<<"$body" && break; sleep 2; done
    if grep -qiE "$check" <<<"$body"; then ok "$slug: public URL https://$host$probe answers through the edge"; else bad "$slug: https://$host$probe -> ${body:0:200}"; fi
}

stage_templates() {
    step "templates: deploy catalog templates (Uptime Kuma, Umami) through the API"
    deploy_template uptime-kuma kuma '{"TIMEZONE":"UTC"}' / 'uptime kuma' || true
    deploy_template umami umami '{}' /api/heartbeat '"ok": ?true' || true
}

fmt_duration() { if (( $1 >= 60 )); then printf '%dm%02ds' $(($1 / 60)) $(($1 % 60)); else printf '%ds' "$1"; fi; }

declare -a T_NAME=() T_SECS=() T_PASS=() T_FAIL=()
run_stage() {
    local name=$1 t0=$SECONDS p0=$pass f0=$fail
    "stage_$name"
    local secs=$((SECONDS - t0))
    T_NAME+=("$name"); T_SECS+=("$secs"); T_PASS+=($((pass - p0))); T_FAIL+=($((fail - f0)))
    printf '  \033[2m-- %s: %s\033[0m\n' "$name" "$(fmt_duration "$secs")"
}

for s in $STAGES; do
    if ! declare -F "stage_$s" >/dev/null; then echo "unknown stage '$s' (stages: $ALL_STAGES)" >&2; exit 2; fi
done
run_start=$SECONDS
for s in $STAGES; do
    if [[ " $SKIP " == *" $s "* ]]; then continue; fi
    run_stage "$s"
done
total=$((SECONDS - run_start))

printf '\n\033[1m%-18s %9s %6s %6s\033[0m\n' stage time pass fail
{
    for i in "${!T_NAME[@]}"; do
        printf '%-18s %9s %6d %6d\n' "${T_NAME[$i]}" "$(fmt_duration "${T_SECS[$i]}")" "${T_PASS[$i]}" "${T_FAIL[$i]}"
    done
    printf '%-18s %9s %6d %6d\n' total "$(fmt_duration "$total")" "$pass" "$fail"
}
# Machine-readable timings, appended per run (compare runs: column -t .data/e2e-timings.tsv).
run_id=$(date +%Y-%m-%dT%H:%M:%S)
for i in "${!T_NAME[@]}"; do
    printf '%s\t%s\t%s\t%s\t%s\n' "$run_id" "${T_NAME[$i]}" "${T_SECS[$i]}" "${T_PASS[$i]}" "${T_FAIL[$i]}"
done >> .data/e2e-timings.tsv
printf '%s\ttotal\t%s\t%s\t%s\n' "$run_id" "$total" "$pass" "$fail" >> .data/e2e-timings.tsv

printf '\n==> e2e-deploy: %d passed, %d failed in %s\n' "$pass" "$fail" "$(fmt_duration "$total")"
(( fail == 0 ))
