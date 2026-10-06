#!/usr/bin/env bash
# Scripted test for the key-encryption key in falak-ctl: `kek init` creates 32 random bytes at secrets/kek once
# (0400, directory 0711) and never replaces it, a wrong-size file stops it, the emergency kit round-trips through
# `kek export` / `kek import`, and the KEK id matches the control plane's. Docker is not needed.
#   deploy/tests/falak-ctl-kek.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-kek.XXXXXX")"
trap 'chmod -R u+w "$work" 2>/dev/null; rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1
mkdir -p "$FALAK_DIR"
printf 'FALAK_DOMAIN=falak.example.com\n' > "$FALAK_DIR/.env"

# shellcheck source=/dev/null
. "$here/../falak-ctl"
is_running() { return 1; }   # no stack here

ensure_kek --quiet
[ -f "$KEK_FILE" ] || fail "no KEK created"
[ "$(file_size "$KEK_FILE")" = 32 ] || fail "KEK size $(file_size "$KEK_FILE")"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "KEK mode $(file_mode "$KEK_FILE")"
[ "$(file_mode "$SECRETS_DIR")" = 711 ] || fail "secrets dir mode $(file_mode "$SECRETS_DIR")"
id="$(kek_fingerprint "$KEK_FILE")"
[ "${#id}" = 16 ] || fail "KEK id $id"
pass "kek init creates a 32-byte KEK, mode 0400, in a 0711 directory"

chmod 0644 "$KEK_FILE"
ensure_kek --quiet
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "kek init replaced the KEK"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "loose mode not fixed"
pass "kek init keeps an existing KEK and tightens its mode"

if command -v php >/dev/null 2>&1; then
  # shellcheck disable=SC2016  # PHP code
  php_id="$(php -r 'echo substr(hash("sha256", "falak-kek-id:".file_get_contents($argv[1])), 0, 16);' "$KEK_FILE")"
  [ "$php_id" = "$id" ] || fail "falak-ctl id $id != control plane id $php_id"
  pass "the KEK id matches the control plane's (LocalKek::fingerprint)"
fi

out="$(kek_export 2>&1)" || true
grep -q 'usage: falak-ctl kek export <file>' <<<"$out" || fail "export without a file: $out"
grep -q 'BEGIN FALAK KEK' <<<"$out" && fail "export without a file printed the key"
pass "kek export without a file prints usage, never the key"

kit="$work/kit.txt"
kek_export "$kit" >/dev/null
[ "$(file_mode "$kit")" = 600 ] || fail "kit mode $(file_mode "$kit")"
grep -q "^KEK id:    $id\$" "$kit" || fail "kit has no KEK id"
grep -q '^-----BEGIN FALAK KEK-----$' "$kit" || fail "kit has no key block"
(kek_export "$kit" >/dev/null 2>&1) && fail "export overwrote an existing file"
pass "kek export writes a 0600 kit with the KEK id and refuses to overwrite"

# A new host: import the kit.
saved="$FALAK_DIR"
export FALAK_DIR="$work/other"
mkdir -p "$FALAK_DIR"; cp "$saved/.env" "$FALAK_DIR/.env"
# shellcheck disable=SC2034  # read by the sourced falak-ctl functions
{
  ENV_FILE="$FALAK_DIR/.env"
  SECRETS_DIR="$FALAK_DIR/secrets"
  KEK_FILE="$SECRETS_DIR/kek"
  KEK_PREVIOUS="$SECRETS_DIR/kek.previous"
}
kek_import "$kit" >/dev/null
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "imported KEK differs"
[ "$(file_mode "$KEK_FILE")" = 400 ] || fail "imported KEK mode $(file_mode "$KEK_FILE")"
cmp -s "$KEK_FILE" "$saved/secrets/kek" || fail "imported bytes differ"
pass "kek import restores the exact KEK from the kit"

chmod 0600 "$KEK_FILE"; head -c 32 /dev/urandom > "$KEK_FILE"; chmod 0400 "$KEK_FILE"
other="$(kek_fingerprint "$KEK_FILE")"
(kek_import "$kit" >/dev/null 2>&1) && fail "import replaced a different KEK without --force"
[ "$(kek_fingerprint "$KEK_FILE")" = "$other" ] || fail "KEK changed without --force"
kek_import "$kit" --force >/dev/null
[ "$(kek_fingerprint "$KEK_FILE")" = "$id" ] || fail "--force did not import"
ls "$SECRETS_DIR"/kek.replaced-* >/dev/null 2>&1 || fail "the replaced KEK was not kept"
pass "kek import refuses to replace another KEK unless --force, and keeps the old one aside"

sed 's/^KEK id:.*/KEK id:    0000000000000000/' "$kit" > "$work/damaged.txt"
(kek_import "$work/damaged.txt" --force >/dev/null 2>&1) && fail "a kit whose key does not match its id was imported"
pass "kek import checks the key against the kit's KEK id"

chmod 0600 "$KEK_FILE"; head -c 16 /dev/urandom > "$KEK_FILE"
(ensure_kek --quiet >/dev/null 2>&1) && fail "a 16-byte KEK was accepted"
[ "$(file_size "$KEK_FILE")" = 16 ] || fail "kek init replaced a wrong-size KEK"
pass "kek init stops on a wrong-size KEK instead of replacing it"

printf 'FALAK_KEK_PROVIDER=aws-kms\n' >> "$FALAK_DIR/.env"
rm -rf "$SECRETS_DIR"
ensure_kek --quiet
[ ! -e "$SECRETS_DIR" ] || fail "kek init created a file for aws-kms"
pass "kek init does nothing when the KEK is held by KMS / Vault"
