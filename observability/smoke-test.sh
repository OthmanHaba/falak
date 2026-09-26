#!/usr/bin/env bash
# Kiln observability smoke test.
#
# Sends one OTLP/JSON trace, log and metric through the gateway (:4318) and verifies each
# is queryable from its backend (Tempo search API, Loki query_range, Prometheus query API),
# plus that Tempo's span-metrics reach the metrics backend.
#
# Usage:
#   ./smoke-test.sh                        # test an already-running stack
#   ./smoke-test.sh --stack victoriametrics  # up -> wait healthy -> test -> down -v
#   ./smoke-test.sh --stack mimir
#   ./smoke-test.sh --stack all            # both backends, one after the other
#
# Host ports honour the same env vars as compose.yml:
#   KILN_OTLP_PORT (4318) KILN_LOKI_PORT (3100) KILN_TEMPO_PORT (3200) KILN_METRICS_QUERY_PORT (9090)
# Set KILN_OTLP_TOKEN if the gateway requires a bearer token.
set -euo pipefail

cd "$(dirname "$0")"

OTLP_URL="${OTLP_URL:-http://127.0.0.1:${KILN_OTLP_PORT:-4318}}"
LOKI_URL="${LOKI_URL:-http://127.0.0.1:${KILN_LOKI_PORT:-3100}}"
TEMPO_URL="${TEMPO_URL:-http://127.0.0.1:${KILN_TEMPO_PORT:-3200}}"
METRICS_URL="${METRICS_URL:-http://127.0.0.1:${KILN_METRICS_QUERY_PORT:-9090}/prometheus}"
TIMEOUT="${SMOKE_TIMEOUT:-120}"
COMPOSE="${COMPOSE:-docker compose}"

pass=0; fail=0
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$*"; pass=$((pass+1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$*"; fail=$((fail+1)); }
info() { printf '==> %s\n' "$*"; }
skip() { printf '  \033[33mSKIP\033[0m %s\n' "$*"; }

# otlp_metric_pb <run-id> <time-ns>: ExportMetricsServiceRequest (protobuf) with one gauge
# kiln.smoke.value=42{run=<run-id>}; hand-encoded with the python3 stdlib (no deps).
otlp_metric_pb() {
  python3 - "$1" "$2" <<'PY'
import struct, sys
run, ts = sys.argv[1], int(sys.argv[2])
def varint(n):
    out = b""
    while True:
        b = n & 0x7F; n >>= 7
        out += bytes([b | (0x80 if n else 0)])
        if not n: return out
def ld(field, payload):  # length-delimited
    return varint(field << 3 | 2) + varint(len(payload)) + payload
def s(field, text): return ld(field, text.encode())
def attr(field, k, v): return ld(field, s(1, k) + ld(2, s(1, v)))
resource = b"".join(attr(1, k, v) for k, v in [
    ("service.name", "kiln-smoke"), ("host.name", "smoke-host"),
    ("kiln.site.id", "01SMOKESITE0000000000000000"), ("kiln.server.id", "01SMOKESERVER00000000000000")])
point = attr(7, "run", run) + varint(3 << 3 | 1) + struct.pack("<Q", ts) + varint(4 << 3 | 1) + struct.pack("<d", 42.0)
metric = s(1, "kiln.smoke.value") + s(2, "smoke test gauge") + ld(5, ld(1, point))
scope = ld(1, s(1, "kiln-smoke")) + ld(2, metric)
rm = ld(1, resource) + ld(2, scope)
sys.stdout.buffer.write(ld(1, rm))
PY
}

auth=()
[[ -n "${KILN_OTLP_TOKEN:-}" ]] && auth=(-H "Authorization: Bearer ${KILN_OTLP_TOKEN}")

# poll <seconds> <cmd...>: retry until cmd succeeds
poll() {
  local deadline=$(( $(date +%s) + $1 )); shift
  until "$@"; do
    (( $(date +%s) >= deadline )) && return 1
    sleep 2
  done
}

wait_ready() {
  info "waiting for gateway readiness (logs, traces, metrics) at ${OTLP_URL}"
  local p
  for p in logs traces metrics; do
    if poll "$TIMEOUT" curl -fs -o /dev/null "${OTLP_URL}/ready/${p}"; then ok "ready: ${p}"; else bad "ready: ${p} (timeout ${TIMEOUT}s)"; return 1; fi
  done
}

run_checks() {
  local backend="${1:-running stack}"
  local rid trace_id span_id now_ns end_ns
  rid="smoke$(date +%s)$RANDOM"
  trace_id="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
  span_id="$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
  now_ns="$(date +%s)000000000"
  end_ns="$(( $(date +%s) ))250000000"

  info "[$backend] run id ${rid}, trace ${trace_id}"

  local resource
  resource='{"attributes":[
    {"key":"service.name","value":{"stringValue":"kiln-smoke"}},
    {"key":"host.name","value":{"stringValue":"smoke-host"}},
    {"key":"kiln.site.id","value":{"stringValue":"01SMOKESITE0000000000000000"}},
    {"key":"kiln.server.id","value":{"stringValue":"01SMOKESERVER00000000000000"}}]}'

  # --- send -----------------------------------------------------------------------------
  local code
  code=$(curl -sS -o /dev/null -w '%{http_code}' ${auth[@]+"${auth[@]}"} -H 'Content-Type: application/json' \
    -X POST "${OTLP_URL}/v1/traces" --data @- <<JSON
{"resourceSpans":[{"resource":${resource},"scopeSpans":[{"scope":{"name":"kiln-smoke"},"spans":[{
  "traceId":"${trace_id}","spanId":"${span_id}","name":"GET /smoke/{id}","kind":2,
  "startTimeUnixNano":"${now_ns}","endTimeUnixNano":"${end_ns}",
  "attributes":[
    {"key":"kiln.event.type","value":{"stringValue":"request"}},
    {"key":"http.request.method","value":{"stringValue":"GET"}},
    {"key":"http.route","value":{"stringValue":"/smoke/{id}"}},
    {"key":"http.response.status_code","value":{"intValue":"200"}},
    {"key":"url.path","value":{"stringValue":"/smoke/${rid}"}},
    {"key":"kiln.smoke.run","value":{"stringValue":"${rid}"}}],
  "status":{"code":1}}]}]}]}
JSON
)
  [[ "$code" =~ ^2 ]] && ok "POST /v1/traces -> ${code}" || bad "POST /v1/traces -> ${code}"

  code=$(curl -sS -o /dev/null -w '%{http_code}' ${auth[@]+"${auth[@]}"} -H 'Content-Type: application/json' \
    -X POST "${OTLP_URL}/v1/logs" --data @- <<JSON
{"resourceLogs":[{"resource":${resource},"scopeLogs":[{"scope":{"name":"kiln-smoke"},"logRecords":[{
  "timeUnixNano":"${now_ns}","observedTimeUnixNano":"${now_ns}",
  "severityNumber":9,"severityText":"INFO",
  "body":{"stringValue":"kiln smoke log ${rid}"},
  "traceId":"${trace_id}","spanId":"${span_id}",
  "attributes":[{"key":"kiln.smoke.run","value":{"stringValue":"${rid}"}}]}]}]}]}
JSON
)
  [[ "$code" =~ ^2 ]] && ok "POST /v1/logs -> ${code}" || bad "POST /v1/logs -> ${code}"

  # Metrics are sent twice: OTLP/protobuf (what kiln-agent exports; must work on every
  # backend) and OTLP/JSON (VictoriaMetrics only accepts protobuf, so JSON is informational).
  code=$(otlp_metric_pb "$rid" "$now_ns" | curl -sS -o /dev/null -w '%{http_code}' ${auth[@]+"${auth[@]}"} \
    -H 'Content-Type: application/x-protobuf' -X POST "${OTLP_URL}/v1/metrics" --data-binary @-)
  [[ "$code" =~ ^2 ]] && ok "POST /v1/metrics (protobuf) -> ${code}" || bad "POST /v1/metrics (protobuf) -> ${code}"

  local json_body
  json_body=$(curl -sS -w '\n%{http_code}' ${auth[@]+"${auth[@]}"} -H 'Content-Type: application/json' \
    -X POST "${OTLP_URL}/v1/metrics" --data @- <<JSON
{"resourceMetrics":[{"resource":${resource},"scopeMetrics":[{"scope":{"name":"kiln-smoke"},"metrics":[{
  "name":"kiln.smoke.json","description":"smoke test gauge (json)",
  "gauge":{"dataPoints":[{"timeUnixNano":"${now_ns}","asDouble":7,
    "attributes":[{"key":"run","value":{"stringValue":"${rid}"}}]}]}}]}]}]}
JSON
)
  code="${json_body##*$'\n'}"
  local json_ok=0
  if [[ "$code" =~ ^2 ]]; then ok "POST /v1/metrics (json) -> ${code}"; json_ok=1
  elif grep -q "json encoding isn't supported" <<<"$json_body"; then
    skip "POST /v1/metrics (json) -> ${code}: backend accepts OTLP metrics as protobuf only (VictoriaMetrics); agent exports protobuf"
  else bad "POST /v1/metrics (json) -> ${code}: ${json_body%$'\n'*}"; fi

  # --- query ----------------------------------------------------------------------------
  local q out
  q="{ resource.service.name = \"kiln-smoke\" && span.kiln.smoke.run = \"${rid}\" }"
  tempo_found() {
    out=$(curl -fsS -G "${TEMPO_URL}/api/search" --data-urlencode "q=${q}" \
      --data-urlencode "start=$(( $(date +%s) - 600 ))" --data-urlencode "end=$(( $(date +%s) + 60 ))" 2>/dev/null) || return 1
    # Tempo drops leading zeros from trace ids in search results.
    grep -q "\"traceID\":\"$(sed 's/^0*//' <<<"$trace_id")\"" <<<"$out"
  }
  if poll "$TIMEOUT" tempo_found; then ok "Tempo search (TraceQL) finds trace ${trace_id}"; else bad "Tempo search did not return trace (last: ${out:-<none>})"; fi

  loki_found() {
    out=$(curl -fsS -G "${LOKI_URL}/loki/api/v1/query_range" \
      --data-urlencode "query={service_name=\"kiln-smoke\"} |= \"${rid}\"" \
      --data-urlencode "start=$(( $(date +%s) - 600 ))000000000" --data-urlencode "limit=10" 2>/dev/null) || return 1
    grep -q "kiln smoke log ${rid}" <<<"$out"
  }
  if poll "$TIMEOUT" loki_found; then
    ok "Loki query_range finds log line"
    grep -q "\"trace_id\":\"${trace_id}\"" <<<"$out" && ok "Loki log carries trace_id structured metadata" || bad "Loki log missing trace_id metadata: $out"
    grep -q '"host_name":"smoke-host"' <<<"$out" && ok "Loki indexes host.name as host_name" || bad "Loki host_name label missing: $out"
  else bad "Loki query_range returned nothing (last: ${out:-<none>})"; fi

  metric_found() {
    out=$(curl -fsS -G "${METRICS_URL}/api/v1/query" --data-urlencode "query=kiln_smoke_value{run=\"${rid}\"}" 2>/dev/null) || return 1
    grep -q '"value":\[[^]]*"42"\]' <<<"$out"
  }
  if poll "$TIMEOUT" metric_found; then
    ok "Prometheus API returns kiln_smoke_value{run=\"${rid}\"} = 42"
    grep -q '"host_name":"smoke-host"' <<<"$out" && ok "metric carries host_name label" || bad "metric missing host_name label: $out"
    grep -q '"kiln_site_id":"01SMOKESITE0000000000000000"' <<<"$out" && ok "metric carries kiln_site_id label" || bad "metric missing kiln_site_id label: $out"
  else bad "Prometheus API did not return metric (last: ${out:-<none>})"; fi

  if (( json_ok )); then
    json_metric_found() {
      out=$(curl -fsS -G "${METRICS_URL}/api/v1/query" --data-urlencode "query=kiln_smoke_json{run=\"${rid}\"}" 2>/dev/null) || return 1
      grep -q '"value":\[[^]]*"7"\]' <<<"$out"
    }
    if poll "$TIMEOUT" json_metric_found; then ok "Prometheus API returns JSON-ingested kiln_smoke_json = 7"; else bad "JSON-ingested metric not queryable (last: ${out:-<none>})"; fi
  fi

  spanmetrics_found() {
    out=$(curl -fsS -G "${METRICS_URL}/api/v1/query" \
      --data-urlencode 'query=traces_spanmetrics_calls_total{service="kiln-smoke",kiln_event_type="request",http_route="/smoke/{id}"}' 2>/dev/null) || return 1
    grep -q '"kiln_site_id":"01SMOKESITE0000000000000000"' <<<"$out"
  }
  if poll "$TIMEOUT" spanmetrics_found; then ok "Tempo span-metrics remote-written (traces_spanmetrics_calls_total by kiln_event_type/http_route/kiln_site_id)"
  else bad "span-metrics not found in metrics backend (last: ${out:-<none>})"; fi
}

stack_cycle() {
  local profile="$1"
  info "=== backend: ${profile} ==="
  $COMPOSE --profile "$profile" up -d --quiet-pull
  if wait_ready; then run_checks "$profile"; fi
  $COMPOSE --profile "$profile" ps --format 'table {{.Name}}\t{{.Image}}\t{{.Status}}'
  info "tearing down ${profile}"
  $COMPOSE --profile victoriametrics --profile mimir down -v --remove-orphans >/dev/null 2>&1
}

if [[ "${1:-}" == "--stack" ]]; then
  case "${2:-}" in
    victoriametrics|mimir) stack_cycle "$2" ;;
    all) stack_cycle victoriametrics; stack_cycle mimir ;;
    *) echo "usage: $0 [--stack victoriametrics|mimir|all]" >&2; exit 2 ;;
  esac
else
  wait_ready && run_checks
fi

echo
info "smoke test: ${pass} passed, ${fail} failed"
(( fail == 0 ))
