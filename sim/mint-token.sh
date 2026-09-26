#!/usr/bin/env bash
# Dev shortcut: mint a one-time agent install token through Fleet's public contract
# (Kiln\Fleet\Contracts\Enrollment::issueInstallToken) until the Servers UI/API exists.
#   ./mint-token.sh            -> prints the token
# SIM_ORG_ID defaults to a fixed sim organisation ULID.
set -euo pipefail
cd "$(dirname "$0")"
org="${SIM_ORG_ID:-01K5S1M0RG0000000000000000}"
docker compose -p kiln-sim --env-file sim.env --env-file .data/secrets.env exec -T -e SIM_ORG_ID="$org" control-plane \
  php artisan tinker --execute='echo app(Kiln\Fleet\Contracts\Enrollment::class)->issueInstallToken(getenv("SIM_ORG_ID"))->token, PHP_EOL;' \
  | tr -d '\r' | grep -E '^[A-Za-z0-9]{20,128}$' | tail -1
