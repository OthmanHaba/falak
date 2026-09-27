#!/usr/bin/env bash
# Print a throwaway .env for validating deploy/compose.yml (CI, local tests). Never use it in production:
# install.sh generates the real one.
#   deploy/tools/test-env.sh [domain] [image-prefix] [version] > /tmp/kiln.env
set -euo pipefail
domain="${1:-kiln.example.com}"; prefix="${2:-ghcr.io/owner}"; version="${3:-v0.0.0-test}"
hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }
cat <<ENV
APP_KEY=base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')
DB_PASSWORD=$(hex 24)
REDIS_PASSWORD=$(hex 24)
REVERB_APP_ID=kiln
REVERB_APP_KEY=$(hex 10)
REVERB_APP_SECRET=$(hex 20)
KILN_BUILDER_TOKEN=kbt_$(hex 24)
KILN_OTLP_TOKEN=$(hex 24)
GRAFANA_ADMIN_PASSWORD=$(hex 16)
KILN_REPO=OWNER/kiln
KILN_IMAGE_PREFIX=$prefix
KILN_VERSION=$version
KILN_DOMAIN=$domain
KILN_URL=https://$domain
KILN_AGENT_API_HOST=agents.$domain
KILN_AGENT_API_URL=https://agents.$domain/agent/v1
KILN_ACME_EMAIL=ops@example.com
KILN_TLS=acme
KILN_HTTP_PORT=80
KILN_HTTPS_PORT=443
KILN_EDGE_SUBNET=10.213.77.0/24
ENV
