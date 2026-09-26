#!/usr/bin/env bash
# Kiln sim end-to-end checks. Run after `make up`.
# Steps marked PENDING depend on components still being built (see README.md).
set -uo pipefail
cd "$(dirname "$0")"

set -a; . ./sim.env; . ./.data/secrets.env; set +a
COMPOSE=(docker compose -p kiln-sim --env-file sim.env --env-file .data/secrets.env --profile victoriametrics --profile mimir)
SERVERS=(srv-app-1 srv-app-2 srv-db-1)
EDGE="https://kiln.test:${SIM_EDGE_HTTPS_PORT}"
RESOLVE=(--resolve "kiln.test:${SIM_EDGE_HTTPS_PORT}:127.0.0.1")
mkdir -p .data

pass=0; fail=0; pending=0
ok()      { printf '  \033[32mPASS\033[0m %s\n' "$*"; pass=$((pass+1)); }
bad()     { printf '  \033[31mFAIL\033[0m %s\n' "$*"; fail=$((fail+1)); }
pend()    { printf '  \033[33mPENDING\033[0m %s\n' "$*"; pending=$((pending+1)); }
section() { printf '\n==> %s\n' "$*"; }
dexec()   { "${COMPOSE[@]}" exec -T "$@"; }

# ---------------------------------------------------------------------------------------
section "1. containers running / healthy"
deadline=$(( $(date +%s) + ${E2E_WAIT:-180} ))
while :; do
  report=$("${COMPOSE[@]}" ps -a --format '{{.Service}}|{{.State}}|{{.Health}}')
  unhealthy=$(awk -F'|' '$2!="running" || ($3!="" && $3!="healthy")' <<<"$report")
  [[ -z "$unhealthy" ]] && break
  (( $(date +%s) >= deadline )) && break
  sleep 3
done
while IFS='|' read -r svc state health; do
  [[ -z "$svc" ]] && continue
  if [[ "$state" == running && ( -z "$health" || "$health" == healthy ) ]]; then
    ok "$(printf '%-16s %s%s' "$svc" "$state" "${health:+ ($health)}")"
  else
    bad "$(printf '%-16s %s%s' "$svc" "$state" "${health:+ ($health)}")"
  fi
done <<<"$report"
for s in postgres valkey control-plane horizon reverb edge gateway loki tempo grafana "${SERVERS[@]}"; do
  grep -q "^${s}|" <<<"$report" || bad "service ${s} is not running"
done

# ---------------------------------------------------------------------------------------
section "2. control plane through the edge (TLS, internal CA)"
if "${COMPOSE[@]}" cp edge:/kiln/edge-pki/root.crt .data/edge-root.crt >/dev/null 2>&1; then
  ok "edge root CA exported to sim/.data/edge-root.crt ($(openssl x509 -in .data/edge-root.crt -noout -subject 2>/dev/null))"
else
  bad "could not export edge root CA"
fi
code=$(curl -sS -o .data/up.html -w '%{http_code}' --cacert .data/edge-root.crt "${RESOLVE[@]}" "${EDGE}/up" 2>&1)
[[ "$code" == 200 ]] && ok "GET ${EDGE}/up -> 200 (certificate verified against edge CA)" || bad "GET ${EDGE}/up -> ${code}"
for s in "${SERVERS[@]}"; do
  code=$(dexec "$s" curl -sS -o /dev/null -w '%{http_code}' https://kiln.test/up 2>&1)
  [[ "$code" == 200 ]] && ok "${s}: GET https://kiln.test/up -> 200 over the fleet network (system trust)" || bad "${s}: GET https://kiln.test/up -> ${code}"
done

# ---------------------------------------------------------------------------------------
section "3. agent mTLS at the edge (contracts/agent-protocol)"
code=$(curl -sS -o .data/hb.json -w '%{http_code}' --cacert .data/edge-root.crt "${RESOLVE[@]}" -X POST "${EDGE}/agent/v1/heartbeat" 2>&1)
if [[ "$code" == 401 ]] && grep -q "client certificate required" .data/hb.json; then ok "POST /agent/v1/heartbeat without client cert -> 401 at the edge"
else bad "POST /agent/v1/heartbeat without client cert -> ${code} $(head -c 200 .data/hb.json)"; fi
code=$(curl -sS -o .data/enroll.json -w '%{http_code}' --cacert .data/edge-root.crt "${RESOLVE[@]}" -H 'Content-Type: application/json' -X POST -d '{}' "${EDGE}/agent/v1/enroll" 2>&1)
if ! grep -q "client certificate required" .data/enroll.json && [[ "$code" != 000 && "$code" != 502 ]]; then ok "POST /agent/v1/enroll without client cert passes the edge (control plane answered ${code})"
else bad "POST /agent/v1/enroll -> ${code} $(head -c 200 .data/enroll.json)"; fi
code=$(curl -sS -o /dev/null -w '%{http_code}' --cacert .data/edge-root.crt "${RESOLVE[@]}" -H 'X-Kiln-Client-Cert-Fingerprint: deadbeef' -X POST "${EDGE}/agent/v1/heartbeat" 2>&1)
[[ "$code" == 401 ]] && ok "spoofed X-Kiln-Client-Cert-Fingerprint header without cert -> 401" || bad "spoofed fingerprint header -> ${code}"

# Fingerprint forwarding with a throwaway CA in a disposable edge (never touches the Fleet CA).
tmp=$(mktemp -d); cid=""
cleanup_mtls() { [[ -n "$cid" ]] && docker rm -f "$cid" >/dev/null 2>&1; rm -rf "$tmp"; }
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 1 -subj "/CN=e2e test CA" \
  -keyout "$tmp/ca.key" -out "$tmp/ca.pem" >/dev/null 2>&1
openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -subj "/CN=e2e-agent" -keyout "$tmp/agent.key" -out "$tmp/agent.csr" >/dev/null 2>&1
printf 'extendedKeyUsage=clientAuth\n' > "$tmp/ext"
openssl x509 -req -in "$tmp/agent.csr" -CA "$tmp/ca.pem" -CAkey "$tmp/ca.key" -CAcreateserial -days 1 -extfile "$tmp/ext" -out "$tmp/agent.pem" >/dev/null 2>&1
want=$(openssl x509 -in "$tmp/agent.pem" -outform der | openssl dgst -sha256 -r | cut -d' ' -f1)
chmod 644 "$tmp"/*
cid=$(docker run -d --network kiln-sim_backend -v "$tmp:/kiln/ca:ro" -v "$tmp:/e2e:ro" kiln-sim/edge:dev 2>/dev/null)
if [[ -n "$cid" ]]; then
  for _ in $(seq 1 20); do docker exec "$cid" test -s /data/caddy/pki/authorities/local/root.crt 2>/dev/null && break; sleep 1; done
  sleep 1
  got=$(docker exec "$cid" curl -sk --cert /e2e/agent.pem --key /e2e/agent.key https://localhost/_edge/whoami | sed -n 's/.*"fingerprint":"\([0-9a-f]*\)".*/\1/p')
  [[ -n "$got" && "$got" == "$want" ]] && ok "edge forwards X-Kiln-Client-Cert-Fingerprint = sha256(DER) lowercase hex (${got:0:16}...)" || bad "fingerprint mismatch: edge=${got:-<none>} expected=${want}"
  code=$(docker exec "$cid" curl -sk -o /dev/null -w '%{http_code}' --cert /e2e/agent.pem --key /e2e/agent.key -X POST https://localhost/agent/v1/heartbeat)
  body=$(docker exec "$cid" curl -sk --cert /e2e/agent.pem --key /e2e/agent.key -X POST https://localhost/agent/v1/heartbeat | head -c 120)
  if ! grep -q "client certificate required" <<<"$body"; then ok "valid client cert passes the edge to the control plane (control plane answered ${code}: unknown agent)"
  else bad "valid client cert rejected by edge"; fi
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 1 -subj "/CN=rogue" -keyout "$tmp/rogue.key" -out "$tmp/rogue.pem" >/dev/null 2>&1; chmod 644 "$tmp"/rogue*
  code=$(docker exec "$cid" curl -sk -o /dev/null -w '%{http_code}' --cert /e2e/rogue.pem --key /e2e/rogue.key -X POST https://localhost/agent/v1/heartbeat 2>/dev/null)
  [[ "$code" == 000 ]] && ok "client cert from an untrusted CA is rejected in the TLS handshake" || bad "untrusted client cert -> ${code} (expected handshake failure)"
else
  bad "could not start disposable edge for the mTLS test"
fi
cleanup_mtls

if dexec edge test -s /kiln/ca/ca.pem 2>/dev/null; then
  ok "edge trusts the Fleet CA from /kiln/ca/ca.pem ($(dexec edge openssl x509 -in /etc/caddy/trust/ca.pem -noout -subject 2>/dev/null | tr -d '\r'))"
  "${COMPOSE[@]}" cp edge:/kiln/ca/ca.pem .data/fleet-ca.pem >/dev/null 2>&1
  AGENTS="https://agents.kiln.test:${SIM_EDGE_HTTPS_PORT}"
  ARES=(--resolve "agents.kiln.test:${SIM_EDGE_HTTPS_PORT}:127.0.0.1")
  code=$(curl -sS -o /dev/null -w '%{http_code}' --cacert .data/fleet-ca.pem "${ARES[@]}" "${AGENTS}/up" 2>&1)
  [[ "$code" == 200 ]] && ok "agent API ${AGENTS} serves a server cert issued by the Fleet CA (agents pin it)" || bad "agent API with Fleet CA trust -> ${code}"
  code=$(curl -sS -o /dev/null -w '%{http_code}' --cacert .data/fleet-ca.pem "${ARES[@]}" -X POST "${AGENTS}/agent/v1/heartbeat" 2>&1)
  [[ "$code" == 401 ]] && ok "agent API heartbeat without client cert -> 401" || bad "agent API heartbeat without client cert -> ${code}"
else
  pend "Fleet CA not written to /kiln/ca/ca.pem yet: the edge uses a placeholder CA, so every non-enroll agent call fails closed"
fi

# ---------------------------------------------------------------------------------------
section "4. observability stack"
if KILN_OTLP_PORT="$KILN_OTLP_PORT" KILN_LOKI_PORT="$KILN_LOKI_PORT" KILN_TEMPO_PORT="$KILN_TEMPO_PORT" \
   KILN_METRICS_QUERY_PORT="$KILN_METRICS_QUERY_PORT" SMOKE_TIMEOUT=90 ../observability/smoke-test.sh | sed 's/^/    /'; then
  ok "observability smoke test"
else
  bad "observability smoke test"
fi
for s in "${SERVERS[@]}"; do
  code=$(dexec "$s" curl -sS -o /dev/null -w '%{http_code}' http://gateway:4318/healthz 2>&1)
  [[ "$code" == 200 ]] && ok "${s}: OTLP gateway reachable at http://gateway:4318" || bad "${s}: OTLP gateway -> ${code}"
done

# ---------------------------------------------------------------------------------------
section "5. simulated servers"
for s in "${SERVERS[@]}"; do
  state=$(dexec "$s" systemctl is-system-running 2>/dev/null | tr -d '\r')
  pid1=$(dexec "$s" ps -p 1 -o comm= 2>/dev/null | tr -d '\r ')
  if [[ "$state" == running && "$pid1" == systemd ]]; then ok "${s}: PID 1 = systemd, system state = running"
  else bad "${s}: PID 1 = ${pid1:-?}, state = ${state:-?} ($(dexec "$s" systemctl --failed --no-legend --plain 2>/dev/null | tr '\n' ' '))"; fi
  banner=$(dexec "$s" bash -c 'exec 3<>/dev/tcp/127.0.0.1/22; head -c 7 <&3' 2>/dev/null)
  [[ "$banner" == SSH-2.0 ]] && ok "${s}: sshd answering on :22" || bad "${s}: sshd not answering"
  if dexec "$s" test -x /usr/local/bin/kiln-agent 2>/dev/null; then
    ok "${s}: kiln-agent binary mounted ($(dexec "$s" /usr/local/bin/kiln-agent --version 2>/dev/null | head -1))"
  else
    pend "${s}: kiln-agent binary not built yet (agent/bin/kiln-agent-linux-${SIM_AGENT_ARCH})"
  fi
done
peer=$(dexec srv-app-1 bash -c 'exec 3<>/dev/tcp/srv-db-1/22; head -c 7 <&3' 2>/dev/null)
[[ "$peer" == SSH-2.0 ]] && ok "private network: srv-app-1 -> srv-db-1:22 reachable" || bad "private network: srv-app-1 cannot reach srv-db-1:22"

# ---------------------------------------------------------------------------------------
section "6. agent enrollment, heartbeat and telemetry"
enrolled=0
for s in "${SERVERS[@]}"; do
  if [[ "$(dexec "$s" systemctl is-active kiln-agent 2>/dev/null | tr -d '\r')" != active ]]; then
    pend "${s}: kiln-agent not enrolled/running (make enroll-all, or make enroll TOKEN=...)"
    continue
  fi
  enrolled=$((enrolled+1))
  ok "${s}: kiln-agent enrolled and active"
  errs=$(dexec "$s" journalctl -u kiln-agent --since "-45s" --no-pager -o cat 2>/dev/null | grep -cE '"msg":"(heartbeat|command poll) failed"')
  [[ "$errs" == 0 ]] && ok "${s}: heartbeat + command long-poll over mTLS without errors (last 45s)" || bad "${s}: ${errs} heartbeat/poll errors in the last 45s"
  n=$(curl -fsS -G "http://127.0.0.1:${KILN_METRICS_QUERY_PORT}/prometheus/api/v1/query" \
        --data-urlencode "query=count(system_memory_usage_bytes{host_name=\"${s}\"})" 2>/dev/null | sed -n 's/.*"value":\[[^,]*,"\([0-9]*\)"\].*/\1/p')
  [[ -n "$n" && "$n" -gt 0 ]] && ok "${s}: host metrics from kiln-agent queryable in the metrics backend" || bad "${s}: no host metrics from kiln-agent"
done

# ---------------------------------------------------------------------------------------
section "7. product flows"
echo "  provisioning, deploys, rollbacks, Bun site, APM/Insights/Loki: run \`make e2e-deploy\` (e2e-deploy.sh)"

printf '\n==> e2e: %d passed, %d failed, %d pending\n' "$pass" "$fail" "$pending"
(( fail == 0 ))
