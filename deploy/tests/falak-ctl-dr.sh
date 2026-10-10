#!/usr/bin/env bash
# shellcheck disable=SC2329  # stubs (curl, compose, docker, systemctl …) are called by the sourced falak-ctl
# Scripted test for disaster recovery in falak-ctl and install.sh: `dr setup` writes dr/dr.env (0600 in a 0700
# directory) and the backup / drill timers, never prints a secret and refuses secrets on the command line; uploads
# are refused without the DR passphrase; s3://latest and s3://NAME resolve through a fake S3 (paged listing, SHA-256
# check, keys never on curl's command line); a drill runs in its own compose project (falak-drill) with its own
# directory, ports and subnet and is torn down; dr.json carries no secrets; install.sh parses --restore-from.
# Docker, systemd and S3 are stubbed.
#   deploy/tests/falak-ctl-dr.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/falak-ctl-dr.XXXXXX")"
trap 'chmod -R u+w "$work" 2>/dev/null; rm -rf "$work"' EXIT
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok - %s\n' "$*"; }

export FALAK_DIR="$work/falak" FALAK_CTL_SOURCED=1 FALAK_SYSTEMD_DIR="$work/systemd"
mkdir -p "$FALAK_DIR/deploy" "$FALAK_DIR/observability"
: > "$FALAK_DIR/deploy/compose.yml"
printf 'FALAK_DOMAIN=falak.example.com\nFALAK_VERSION=v0.10.0\nFALAK_IMAGE_PREFIX=ghcr.io/x\nFALAK_EDGE_SUBNET=10.213.77.0/24\nDB_PASSWORD=db\n' > "$FALAK_DIR/.env"
unset FALAK_BACKUP_PASSPHRASE FALAK_BACKUP_S3_ENDPOINT FALAK_BACKUP_S3_BUCKET FALAK_BACKUP_S3_SECRET_KEY FALAK_BACKUP_S3_ACCESS_KEY

# shellcheck source=/dev/null
. "$here/../falak-ctl"
is_running() { return 1; }
systemctl_log="$work/systemctl.log"; : > "$systemctl_log"
systemctl() { printf '%s\n' "$*" >> "$systemctl_log"; }

# --- a fake S3: objects under $work/s3/<bucket>/<key>; listings page two keys at a time ---------------------
S3="$work/s3"; mkdir -p "$S3/dr-bucket"
curl_argv="$work/curl-argv"; : > "$curl_argv"
curl() {
  local out="" upload="" url="" arg
  printf '%s\n' "$*" >> "$curl_argv"
  while [ $# -gt 0 ]; do
    arg="$1"
    case "$arg" in
      -o) out="$2"; shift ;;
      -T) upload="$2"; shift ;;
      -K) cat > /dev/null; shift ;;
      -H|--aws-sigv4|--retry|--user) shift ;;
      -*) ;;
      *) url="$arg" ;;
    esac
    shift
  done
  local path="${url#https://s3.example.com/}" query=""
  case "$path" in *\?*) query="${path#*\?}"; path="${path%%\?*}" ;; esac
  if [ -n "$upload" ]; then mkdir -p "$(dirname "$S3/$path")"; cp "$upload" "$S3/$path"; return 0; fi
  if [ -n "$query" ]; then # ListObjectsV2
    local bucket="${path%/}" prefix token keys start=0
    prefix="$(printf '%s' "$query" | sed -n 's/.*prefix=\([^&]*\).*/\1/p' | sed 's/%2F/\//g')"
    token="$(printf '%s' "$query" | sed -n 's/.*continuation-token=\([^&]*\).*/\1/p')"
    [ -n "$token" ] && start="${token#page}"
    keys="$(cd "$S3/$bucket" && find . -type f | sed 's#^\./##' | grep "^$prefix" | sort || true)"
    printf '<ListBucketResult>'
    printf '%s\n' "$keys" | sed -n "$((start + 1)),$((start + 2))p" | while read -r k; do [ -n "$k" ] && printf '<Contents><Key>%s</Key></Contents>' "$k"; done
    if [ "$(printf '%s\n' "$keys" | grep -c .)" -gt $((start + 2)) ]; then printf '<NextContinuationToken>page%s</NextContinuationToken>' $((start + 2)); fi
    printf '</ListBucketResult>\n'
    return 0
  fi
  [ -f "$S3/$path" ] || return 22
  cp "$S3/$path" "$out"
}

# --- dr setup ------------------------------------------------------------------------------------------------
secret_file="$work/secret"; printf 'S3CRET-KEY-123\n' > "$secret_file"
pass_file="$work/pass"; printf 'correct horse battery staple\n' > "$pass_file"
out="$( (dr_setup --yes --endpoint https://s3.example.com/ --bucket dr-bucket --access-key AKIA1 --secret-key-file "$secret_file" --every 4) 2>&1)" \
  && fail "dr setup went ahead without a passphrase"
grep -q 'passphrase is required' <<<"$out" || fail "refusal without passphrase: $out"
[ ! -f "$FALAK_SYSTEMD_DIR/falak-backup.timer" ] || fail "a timer was installed without a passphrase"
printf 'short\n' > "$work/short"
(dr_setup --yes --endpoint https://s3.example.com --bucket dr-bucket --access-key AKIA1 --secret-key-file "$secret_file" --passphrase-file "$work/short" >/dev/null 2>&1) \
  && fail "a 5-character passphrase was accepted"
out="$( (dr_setup --yes --passphrase hunter2) 2>&1)" && fail "--passphrase on the command line was accepted"
grep -q 'process list' <<<"$out" || fail "--passphrase refusal: $out"
pass "dr setup refuses to go on without a passphrase (12+ characters) and refuses secrets on the command line"

out="$(dr_setup --yes --endpoint https://s3.example.com/ --bucket dr-bucket --region eu-central-1 --access-key AKIA1 \
  --secret-key-file "$secret_file" --passphrase-file "$pass_file" --every 4 --drill monthly 2>&1)" || fail "dr setup failed: $out"
[ "$(file_mode "$DR_DIR")" = 700 ] || fail "dr dir mode $(file_mode "$DR_DIR")"
[ "$(file_mode "$DR_FILE")" = 600 ] || fail "dr.env mode $(file_mode "$DR_FILE")"
grep -q '^FALAK_BACKUP_PASSPHRASE=correct horse battery staple$' "$DR_FILE" || fail "passphrase not saved"
grep -q '^FALAK_BACKUP_S3_SECRET_KEY=S3CRET-KEY-123$' "$DR_FILE" || fail "secret key not saved"
grep -q '^FALAK_BACKUP_S3_ENDPOINT=https://s3.example.com$' "$DR_FILE" || fail "endpoint not normalised"
grep -q 'FALAK_BACKUP_' "$FALAK_DIR/.env" && fail "DR settings leaked into .env"
grep -q 'S3CRET-KEY-123\|correct horse' <<<"$out" && fail "dr setup printed a secret"
grep -q 'S3CRET-KEY-123' "$curl_argv" && fail "the secret key reached curl's command line"
[ -f "$S3/dr-bucket/falak/.falak-dr-check" ] || fail "the bucket was not tested"
pass "dr setup writes dr/dr.env (dir 0700, file 0600), tests the bucket, prints no secret, keeps keys off curl's argv"

grep -q '^OnCalendar=\*-\*-\* 00/4:00:00$' "$FALAK_SYSTEMD_DIR/falak-backup.timer" || fail "backup timer calendar: $(cat "$FALAK_SYSTEMD_DIR/falak-backup.timer")"
grep -q '^Persistent=true$' "$FALAK_SYSTEMD_DIR/falak-backup.timer" || fail "backup timer is not persistent"
grep -q "^ExecStart=.* backup --upload --scheduled" "$FALAK_SYSTEMD_DIR/falak-backup.service" || fail "backup service ExecStart"
grep -q "^Environment=FALAK_DIR=$FALAK_DIR FALAK_PROJECT=falak$" "$FALAK_SYSTEMD_DIR/falak-backup.service" || fail "backup service environment"
grep -q '^ExecStart=.* dr drill --scheduled$' "$FALAK_SYSTEMD_DIR/falak-drill.service" || fail "drill service"
grep -q '^OnCalendar=\*-\*-01 ' "$FALAK_SYSTEMD_DIR/falak-drill.timer" || fail "drill timer is not monthly"
grep -q 'enable --now falak-backup.timer' "$systemctl_log" || fail "backup timer not enabled: $(cat "$systemctl_log")"
grep -q 'enable --now falak-drill.timer' "$systemctl_log" || fail "drill timer not enabled"
grep -q 'PASSPHRASE\|S3CRET' "$FALAK_SYSTEMD_DIR"/falak-* && fail "a unit file holds a secret"
dr_set FALAK_DR_DRILL off; dr_install_timers >/dev/null 2>&1
[ ! -f "$FALAK_SYSTEMD_DIR/falak-drill.timer" ] || fail "--drill off kept the drill timer"
dr_set FALAK_DR_DRILL monthly; dr_install_timers >/dev/null 2>&1
[ "$(dr_calendar 24)" = '*-*-* 03:00:00' ] || fail "daily calendar $(dr_calendar 24)"
(dr_setup --yes --every 5 >/dev/null 2>&1) && fail "--every 5 was accepted"
pass "dr setup installs falak-backup.timer (every N h, persistent) and falak-drill.timer (monthly), without secrets"

dr_configured || fail "not configured after dr setup"
json="$(dr_status --json)"
grep -q '"configured":true' <<<"$json" || fail "dr.json: $json"
grep -q '"schedule_hours":4' <<<"$json" || fail "dr.json schedule: $json"
grep -q '"target":"s3://dr-bucket/falak"' <<<"$json" || fail "dr.json target: $json"
grep -q 'S3CRET\|correct horse\|AKIA1' "$DR_JSON" && fail "dr.json holds a secret"
[ "$(file_mode "$DR_JSON")" = 644 ] || fail "dr.json mode $(file_mode "$DR_JSON")"
if command -v python3 >/dev/null 2>&1; then python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$DR_JSON" || fail "dr.json is not JSON"; fi
pass "dr status --json reports configured, schedule and target; dr.json (0644) holds no secret"

# --- uploads are encrypted or nothing ----------------------------------------------------------------------
mv "$DR_FILE" "$work/dr.env.saved"
dr_set FALAK_BACKUP_S3_ENDPOINT https://s3.example.com; dr_set FALAK_BACKUP_S3_BUCKET dr-bucket
mkdir -p "$BACKUP_DIR"
out="$( (cmd_backup --upload) 2>&1)" && fail "backup --upload went ahead without a passphrase"
grep -q 'needs the DR passphrase' <<<"$out" || fail "--upload refusal: $out"
[ -z "$(find "$BACKUP_DIR" -name 'falak-backup-*')" ] || fail "a refused upload still wrote a backup"
printf 'x' > "$work/falak-backup-plain.tar.gz"
(s3_upload "$work/falak-backup-plain.tar.gz" 1 >/dev/null 2>&1) && fail "an unencrypted backup was uploaded (--upload)"
s3_upload "$work/falak-backup-plain.tar.gz" 0 >/dev/null 2>&1 || fail "the automatic upload failed the backup"
[ ! -f "$S3/dr-bucket/falak/falak-backup-plain.tar.gz" ] || fail "an unencrypted backup left the host"
cmd_backup_scheduled --upload --scheduled >/dev/null 2>&1 && fail "a scheduled backup without passphrase succeeded"
grep -q '^failure_error=.*passphrase' "$DR_STATE" || fail "the scheduled failure was not recorded: $(cat "$DR_STATE")"
grep -q '"last_failure":{"at":"' "$DR_JSON" || fail "dr.json has no failure"
mv "$work/dr.env.saved" "$DR_FILE"
pass "uploads are refused without the DR passphrase and never send a plaintext backup; scheduled failures are recorded"

# --- s3://latest and s3://NAME ----------------------------------------------------------------------------------
mk_backup() { # mk_backup NAME : an encrypted (stub openssl: copy) fake backup uploaded with its .sha256
  local d="$work/mk.$1"; mkdir -p "$d"
  printf 'dump' > "$d/db.dump"; printf 'FALAK_DOMAIN=falak.example.com\nDB_PASSWORD=db\n' > "$d/env"
  printf 'falak_version=v0.10.0\nrows=users:2\n' > "$d/manifest"
  tar -C "$d" -czf "$work/ca.tar.gz" manifest; mv "$work/ca.tar.gz" "$d/falak-ca.tar.gz"
  tar -C "$d" -czf "$work/$1" .
  s3_upload "$work/$1" 1 >/dev/null
}
openssl() { local in="" out=""; while [ $# -gt 0 ]; do case "$1" in -in) in="$2"; shift ;; -out) out="$2"; shift ;; esac; shift; done
  if [ -n "$out" ]; then cp "$in" "$out"; else cat "$in"; fi; }
for ts in 20261001T000000Z 20261003T000000Z 20261002T000000Z 20261004T060000Z-pre-update-v0.9.0; do mk_backup "falak-backup-$ts.tar.gz.enc"; done
[ "$(s3_list "falak/falak-backup-" | grep -vc sha256)" = 4 ] || fail "paged listing: $(s3_list "falak/falak-backup-")"
[ "$(s3_backup_key latest)" = falak/falak-backup-20261004T060000Z-pre-update-v0.9.0.tar.gz.enc ] || fail "latest: $(s3_backup_key latest)"
[ "$(s3_backup_key falak-backup-20261002T000000Z)" = falak/falak-backup-20261002T000000Z.tar.gz.enc ] || fail "name without extension"
[ "$(s3_backup_key falak-backup-20261001T000000Z.tar.gz.enc)" = falak/falak-backup-20261001T000000Z.tar.gz.enc ] || fail "name with extension"
(s3_backup_key falak-backup-19990101T000000Z >/dev/null 2>&1) && fail "an unknown name resolved"
(s3_backup_key ../other/falak-backup-x >/dev/null 2>&1) && fail "a path was accepted"
pass "s3://latest picks the newest backup over paged listings; s3://NAME works with or without .tar.gz.enc"

got="$(restore_source s3://latest 2>/dev/null)"
[ "$got" = "$BACKUP_DIR/falak-backup-20261004T060000Z-pre-update-v0.9.0.tar.gz.enc" ] || fail "restore_source: $got"
[ "$(file_mode "$got")" = 600 ] || fail "downloaded backup mode $(file_mode "$got")"
x="$work/x"; mkdir -p "$x"; extract_backup "$got" "$x"; [ -s "$x/db.dump" ] || fail "downloaded backup does not extract"
printf 'tampered' >> "$S3/dr-bucket/falak/falak-backup-20261003T000000Z.tar.gz.enc"
(restore_source s3://falak-backup-20261003T000000Z >/dev/null 2>&1) && fail "a tampered download was accepted"
[ ! -f "$BACKUP_DIR/falak-backup-20261003T000000Z.tar.gz.enc" ] || fail "a tampered download was kept"
(restore_source s3:// >/dev/null 2>&1) && fail "s3:// without a name was accepted"
(cmd_restore s3://latest >/dev/null 2>&1 </dev/null) && fail "restore without --yes went ahead"
grep -q 'S3CRET-KEY-123' "$curl_argv" && fail "the secret key reached curl's command line"
pass "restore s3://… downloads into backups/ (0600), checks the SHA-256 and needs --yes"

# --- the drill runs in its own project and is torn down ------------------------------------------------------
compose_log="$work/compose.log"; : > "$compose_log"
docker_log="$work/docker.log"; : > "$docker_log"
compose() {
  printf '%s|%s|%s|%s\n' "$FALAK_PROJECT" "$ENV_FILE" "$COMPOSE_FILE" "$*" >> "$compose_log"
  case "$*" in
    *"psql -U falak -d falak -tA"*) printf 'users:2\n' ;;
    *"migrate:status"*) printf '  2026_01_01_000000_x ........ [1] Ran\n' ;;
    *"falak:keys:check --json"*) printf '{"ok":true,"provider":"local","kek_id":"a","data_keys":3,"stale":0,"failed":0,"kek_ids":["a"]}\n' ;;
  esac
  case "$*" in *pg_restore*|*"psql -v ON_ERROR_STOP"*) cat > /dev/null ;; esac
  return 0
}
docker() { printf '%s\n' "$*" >> "$docker_log"; case "$*" in run*) cat > /dev/null ;; esac; return 0; }
kek_secure() { chmod 0400 "$1"; }
ensure_kek --quiet >/dev/null 2>&1 || true
before="$(cat "$FALAK_DIR/.env")"
out="$(dr_drill --backup "$got" 2>&1)" || fail "drill failed: $out"
grep -v '^falak-drill|' "$compose_log" && fail "a drill compose call ran outside falak-drill"
grep -q "^falak-drill|$FALAK_DIR/drill/.env|$FALAK_DIR/drill/deploy/compose.yml|up -d --wait --wait-timeout 600 control-plane" "$compose_log" \
  || fail "the drill's control plane was not started in its own directory: $(cat "$compose_log")"
grep -q '^falak-drill|.*|down -v --remove-orphans$' "$compose_log" || fail "the drill was not torn down"
grep -qE 'up .*(edge|horizon|scheduler|agent-api|builder)' "$compose_log" && fail "the drill started a service that talks to the outside"
grep -q 'falak_' "$docker_log" && fail "the drill touched a volume of the install: $(grep falak_ "$docker_log")"
grep -q 'falak-drill_falak-ca' "$docker_log" || fail "the drill did not restore into its own volumes"
[ ! -e "$FALAK_DIR/drill" ] || fail "the drill directory was left behind"
[ "$(cat "$FALAK_DIR/.env")" = "$before" ] || fail "the drill changed the install's .env"
grep -q '"last_drill":{"at":"[^"]*","ok":true' "$DR_JSON" || fail "dr.json drill: $(cat "$DR_JSON")"
pass "dr drill restores into falak-drill (own dir, volumes, no edge or workers), checks it, tears it down, reports ok"

# The drill's settings: other ports and subnet, this host's version, no pulls.
(
  w="$work/drillwork"; mkdir -p "$w"; extract_backup "$got" "$w"
  drill_prepare "$w" "$work/dd"
  grep -q '^FALAK_HTTP_PORT=18080$' "$work/dd/.env" || fail "drill http port"
  grep -q '^FALAK_HTTPS_PORT=18443$' "$work/dd/.env" || fail "drill https port"
  grep -q '^FALAK_EDGE_SUBNET=10.213.78.0/24$' "$work/dd/.env" || fail "drill subnet: $(grep SUBNET "$work/dd/.env")"
  grep -q '^FALAK_PULL=0$' "$work/dd/.env" || fail "drill pulls"
  grep -q '^FALAK_VERSION=v0.10.0$' "$work/dd/.env" || fail "drill version"
  [ -f "$work/dd/secrets/kek" ] || fail "drill has no KEK"
  env_set FALAK_EDGE_SUBNET 10.213.78.0/24
  [ "$(drill_subnet)" = 10.213.79.0/24 ] || fail "drill subnet collides with the install's"
)
(FALAK_PROJECT=falak; drill_switch "$work/dd"; [ "$FALAK_PROJECT" = falak-drill ] || fail "drill project $FALAK_PROJECT")
pass "the drill uses ports 18080/18443, another edge subnet, this host's version and no pulls"

compose() { case "$*" in *falak:keys:check*) printf '{"ok":false,"stale":0,"failed":2}\n'; return 1 ;; *"psql -U falak -d falak -tA"*) printf 'users:1\n' ;; *pg_restore*) cat >/dev/null ;; esac; return 0; }
(dr_drill --backup "$got" >/dev/null 2>&1) && fail "a drill with failing checks passed"
grep -q '"ok":false' "$DR_JSON" || fail "failed drill not reported"
grep -q 'encryption keys' "$DR_STATE" || fail "failed check not named: $(grep drill_message "$DR_STATE")"
grep -q 'row counts: differ: users 1/2' "$DR_STATE" || fail "row count mismatch not named: $(grep drill_message "$DR_STATE")"
[ ! -e "$FALAK_DIR/drill" ] || fail "a failed drill left its directory"
pass "a failed drill names the failing checks in dr.json and is torn down too"

# --- install.sh --restore-from ----------------------------------------------------------------------------
inst() { ( export FALAK_INSTALL_SOURCED=1 FALAK_DOMAIN=falak.example.com FALAK_EMAIL=ops@example.com FALAK_DIR="$work/inst"
  # shellcheck source=/dev/null
  . "$here/../install.sh" "$@"; printf '%s\n' "$RESTORE_FROM" ) }
[ "$(inst --restore-from s3://latest 2>/dev/null)" = s3://latest ] || fail "--restore-from s3://latest"
[ "$(inst --restore-from=s3://falak-backup-20261004T060000Z 2>/dev/null)" = s3://falak-backup-20261004T060000Z ] || fail "--restore-from=NAME"
[ "$(FALAK_RESTORE_FROM=s3://latest inst 2>/dev/null)" = s3://latest ] || fail "FALAK_RESTORE_FROM"
[ -z "$(inst 2>/dev/null)" ] || fail "restore without the flag"
for bad in /root/backup.tar.gz s3://other/falak-backup-x s3://falak-backup-../x s3:// s3://foo; do
  (inst --restore-from "$bad" >/dev/null 2>&1) && fail "--restore-from $bad was accepted"
done
grep -q 'restore_control_plane' "$here/../install.sh" || fail "install.sh does not restore"
# The schedule is installed only after a successful restore (a timer must never upload an empty install first).
fn="$(awk '/^restore_control_plane\(\)/,/^}/' "$here/../install.sh")"
[ "$(grep -n 'kctl restore' <<<"$fn" | cut -d: -f1)" -lt "$(grep -n 'kctl dr setup' <<<"$fn" | cut -d: -f1)" ] || fail "dr setup runs before the restore"
grep -q -- '--passphrase-file' <<<"$fn" || fail "install.sh hands the passphrase over on the command line"
pass "install.sh parses --restore-from s3://latest | s3://NAME, refuses others, schedules backups only after the restore"
