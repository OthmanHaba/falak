#!/usr/bin/env bash
# Enroll sim servers with the control plane using a one-time install token:
#   ./enroll.sh <token> [server...]      (default: srv-app-1 srv-app-2 srv-db-1)
#
# Runs, inside each server, exactly what a real user runs on a fresh box:
#   curl -fsSL https://falak.test/install/<token> | sh
# The servers trust the edge CA (installed at boot), reach the edge as falak.test over the
# private `fleet` network and the observability gateway at http://gateway:4318.
#
# PENDING: needs a token minted by the control plane (Fleet install tokens UI/API) and a
# built agent binary at agent/bin/falak-agent-linux-<arch> (served by the control plane from
# FALAK_AGENT_BINARIES_PATH=/opt/falak/src/agent/bin). Install tokens are one-time: mint one per
# server, or use a multi-use token if Fleet supports it.
set -euo pipefail
cd "$(dirname "$0")"

token="${1:-}"
if [[ ! "$token" =~ ^[A-Za-z0-9]{20,128}$ ]]; then
  echo "usage: $0 <install-token> [server...]   (token: 20-128 alphanumerics)" >&2
  exit 2
fi
shift
servers=("$@")
(( ${#servers[@]} )) || servers=(srv-app-1 srv-app-2 srv-db-1)

compose=(docker compose -p falak-sim --env-file sim.env --env-file .data/secrets.env)
rc=0
for srv in "${servers[@]}"; do
  echo "==> enrolling ${srv}"
  if "${compose[@]}" exec -T -e FALAK_INSTALL_TOKEN="$token" "$srv" \
       bash -c 'set -o pipefail; curl -fsSL "https://falak.test/install/${FALAK_INSTALL_TOKEN}" | sh'; then
    echo "    ${srv}: installer finished"
    "${compose[@]}" exec -T "$srv" systemctl is-active falak-agent || true
  else
    echo "    ${srv}: installer FAILED" >&2
    rc=1
  fi
done
exit "$rc"
