#!/usr/bin/env bash
# Scripted test for the installer's OS handling: supported releases (Ubuntu 22.04/24.04/26.04, Debian 12) pass the
# preflight check, others need --force, and Docker's apt suite falls back to the previous release when Docker has
# not published the host's codename yet. Network and Docker are stubbed.
#   deploy/tests/install-os.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-install-os.XXXXXX")"
trap 'rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_INSTALL_SOURCED=1 FALAK_DOMAIN=falak.example.com FALAK_EMAIL=ops@example.com
export FALAK_OS_RELEASE="$work/os-release"

# shellcheck source=/dev/null
. "$here/../install.sh"

for os in ubuntu:22.04 ubuntu:24.04 ubuntu:26.04 debian:12; do
  os_supported "${os%%:*}" "${os#*:}" || fail "$os is not supported"
done
pass "Ubuntu 22.04, 24.04, 26.04 and Debian 12 are supported"

for os in ubuntu:20.04 ubuntu:25.10 debian:11 debian:13 fedora:42 :; do
  if os_supported "${os%%:*}" "${os#*:}"; then fail "$os is supported"; fi
done
pass "other releases need --force"

printf 'PRETTY_NAME="Ubuntu Resolute Raccoon"\nID=ubuntu\nVERSION_ID="26.04"\nVERSION_CODENAME=resolute\n' > "$FALAK_OS_RELEASE"
[ "$(os_field ID) $(os_field VERSION_ID) $(os_field VERSION_CODENAME)" = "ubuntu 26.04 resolute" ] || fail "os_field: $(os_field VERSION_ID)"
pass "reads ID, VERSION_ID and VERSION_CODENAME from os-release"

probed="$work/probed"; : > "$probed"
curl_args="$work/curl-args"
docker_down=0 # 1: download.docker.com unreachable (curl's timeout)
curl() { # curl -fsSI --max-time N --retry N --retry-delay N URL : Docker publishes jammy, noble, resolute and bookworm only
  local url="${*: -1}"; printf '%s\n' "$url" >> "$probed"; printf '%s\n' "$*" > "$curl_args"
  if [ "$docker_down" = 1 ]; then return 28; fi
  case "$url" in */dists/jammy/Release|*/dists/noble/Release|*/dists/resolute/Release|*/dists/bookworm/Release) return 0 ;; *) return 22 ;; esac
}

[ "$(docker_codename ubuntu resolute 2>/dev/null)" = resolute ] || fail "resolute: $(docker_codename ubuntu resolute 2>&1)"
grep -qx "https://download.docker.com/linux/ubuntu/dists/resolute/Release" "$probed" || fail "probed: $(cat "$probed")"
grep -q -- "--retry 3" "$curl_args" || fail "the probe does not retry: $(cat "$curl_args")"
[ "$(docker_codename ubuntu noble 2>/dev/null)" = noble ] || fail "noble"
[ "$(docker_codename debian bookworm 2>/dev/null)" = bookworm ] || fail "bookworm"
pass "uses Docker's suite for the host's own codename when it exists (the probe retries)"

# Known codenames are never probed: an unreachable download.docker.com must not swap the suite.
: > "$probed"
docker_down=1
for known in ubuntu:jammy ubuntu:noble debian:bookworm; do
  out="$(docker_codename "${known%%:*}" "${known#*:}" 2>"$work/err")"
  [ "$out" = "${known#*:}" ] || fail "$known: $out"
  [ ! -s "$work/err" ] || fail "$known warned: $(cat "$work/err")"
done
[ ! -s "$probed" ] || fail "known codenames probed: $(cat "$probed")"
pass "jammy, noble and bookworm always use their own suite, without a probe"

out="$(docker_codename ubuntu zesty-next 2>"$work/err")"
[ "$out" = noble ] || fail "fallback ubuntu: $out"
grep -q "no 'zesty-next' suite" "$work/err" || fail "no warning: $(cat "$work/err")"
[ "$(docker_codename debian forky 2>/dev/null)" = bookworm ] || fail "fallback debian"
[ "$(docker_codename ubuntu '' 2>/dev/null)" = noble ] || fail "empty codename"
pass "falls back to noble / bookworm with a warning when Docker has no suite for the codename"
