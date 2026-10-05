#!/usr/bin/env bash
# Fails when a tracked file still names the product's previous name (contents or path).
#   tools/check-brand.sh
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
old='k[i]ln'
status=0
if git grep -n -i -I -E "$old"; then status=1; fi
if git ls-files | grep -i -E "$old"; then status=1; fi
[ "$status" -eq 0 ] || echo "check-brand: the old product name is still present (see above)" >&2
exit "$status"
