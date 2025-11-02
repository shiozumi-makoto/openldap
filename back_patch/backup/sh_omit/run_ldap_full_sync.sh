#!/bin/bash
# LDAP/ホーム 同期フルジョブ（認証は環境変数に統一）
# 1) PostgreSQL -> ホーム作成＆LDAPユーザー 追加/更新
# 2) 全ユーザーを 'users' グループへ追加（memberUid）
# 3) gidNumber に基づき各グループへ追加（memberUid）
# 4) Samba groupmap 反映
# 5) ホーム整理（退職/MISSING_DB を削除 or 退避）＋ LDAP アカウント削除

set -Eeuo pipefail

# ===== 環境 =====
export PATH="/usr/local/bin:/usr/bin:/bin"
export LANG="ja_JP.UTF-8"
export LC_ALL="ja_JP.UTF-8"

# ==== force color for non-tty (tee) ====
export TERM="${TERM:-xterm-256color}"
export CLICOLOR=1
export CLICOLOR_FORCE="${CLICOLOR_FORCE:-1}"
export FORCE_COLOR="${FORCE_COLOR:-1}"
unset NO_COLOR

# ===== 設定 =====
BASE_DIR="/usr/local/etc/openldap/tools"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

# PostgreSQL（.pgpass でもOK）
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}"
export PGDATABASE="${PGDATABASE:-accounting}"

# ===== LDAP接続先 自動判定 =====
HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
HOST_SHORT="$(hostname -s 2>/dev/null || echo "")"

if [[ -n "${LDAPURI:-}" && "${LDAPURI}" == ldapi://* ]]; then
  export LDAP_URL="${LDAP_URL:-$LDAPURI}"
else
  LDAP_URL_DEFAULT="ldaps://ovs-012.e-smile.local"
  if [[ "$HOST_SHORT" == "ovs-012" || "$HOST_FQDN" == "ovs-012.e-smile.local" ]]; then
    if [[ -S /var/run/ldapi ]]; then
      LDAP_URL_DEFAULT='ldapi://%2Fvar%2Frun%2Fldapi'
    fi
  fi
  export LDAP_URL="${LDAP_URL:-$LDAP_URL_DEFAULT}"
fi

PHP_LDAP_URL="${PHP_LDAP_URL:-${LDAPURI:-$LDAP_URL}}"
export PHP_LDAP_URL
export LDAP_BASE_DN="${LDAP_BASE_DN:-ou=Users,dc=e-smile,dc=ne,dc=jp}"
export BIND_DN="${BIND_DN:-cn=Admin,dc=e-smile,dc=ne,dc=jp}"

# === LDAPURI 補完 ===
if [[ -z "${LDAPURI:-}" ]]; then
  if [[ "$LDAP_URL" == ldapi://* ]]; then
    export LDAPURI="$LDAP_URL"
  elif [[ -S /var/run/ldapi ]]; then
    export LDAPURI='ldapi://%2Fvar%2Frun%2Fldapi'
  fi
fi

is_ldapi() { [[ "${LDAPURI:-}" == ldapi://* ]]; }

# === BIND_PW 要否 ===
if ! is_ldapi; then
  if [[ -z "${BIND_DN:-}" ]]; then
    echo "[ERROR] BIND_DN が未設定です（ldaps では必須）" >&2; exit 1
  fi
  if [[ -z "${BIND_PW:-${LDAP_ADMIN_PW:-}${BIND_PW_FILE:+x}}" ]]; then
    echo "[ERROR] BIND_PW が未設定です（BIND_PW / LDAP_ADMIN_PW / BIND_PW_FILE のいずれかが必要）" >&2
    exit 1
  fi
fi

# ---- 互換用 ----
export LDAP_URI="${LDAP_URI:-$LDAP_URL}"
BASE_DN_DERIVED="${LDAP_BASE_DN#ou=Users,}"
export BASE_DN="${BASE_DN:-$BASE_DN_DERIVED}"
export PEOPLE_OU="${PEOPLE_OU:-ou=Users,${BASE_DN}}"
export HOME_ROOT="${HOME_ROOT:-/home}"
export SKEL_DIR="${SKEL_DIR:-/etc/skel}"
export HOME_MODE="${HOME_MODE:-0750}"
export TRASH_DIR="${TRASH_DIR:-/var/tmp/home_trash}"
export LDAP_POSIX="${LDAP_POSIX:-auto}"
export GID_DEFAULT="${GID_DEFAULT:-100}"
export LOGIN_SHELL="${LOGIN_SHELL:-/bin/bash}"

# ===== ログ設定 =====
# ===== ログ設定 =====
export LOG_DIR="${LOG_DIR:-/root/logs}"
mkdir -p "$LOG_DIR"
TS="$(date '+%Y%m%d_%H%M%S')"
LOG_FILE="${LOG_DIR}/ldap_sync_${TS}.log"
export ADD_LOG="${ADD_LOG:-/var/logs_share/add_home_from_db.log}"
export PRUNE_LOG="${PRUNE_LOG:-/var/logs_share/prune_home_dirs.log}"
_strip_ansi='s/\x1B\[[0-9;]*[A-Za-z]//g'
exec > >(tee >(sed -r "$_strip_ansi" >> "$LOG_FILE")) 2>&1


# ===== 関数 =====
mask_pw() { [ -n "${1-}" ] && printf '%s' '********' || printf '%s' ''; }
section() { echo; echo "==================== $* ===================="; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERROR] command not found: $1"; exit 1; }; }

# STEP計測＆トラップ
CURRENT_STEP="(init)"
declare -A STEP_TIME_START STEP_TIME_TOTAL
trap 'echo "[FATAL] Failed at: ${CURRENT_STEP}"; exit 1' ERR

step() {
  CURRENT_STEP="$1"
  section "$CURRENT_STEP"
  STEP_TIME_START["$CURRENT_STEP"]="$(date +%s)"
}
step_done() {
  local name="$1"
  local end=$(date +%s)
  local start="${STEP_TIME_START[$name]}"
  local elapsed=$(( end - start ))
  STEP_TIME_TOTAL["$name"]="$elapsed"
  printf "[TIME] %s: %ds\n" "$name" "$elapsed"
}

# ===== 情報出力 =====
echo "[INFO] start: $(date '+%F %T')"
echo "[INFO] LOG_FILE: $LOG_FILE"
echo "[INFO] PG=${PGHOST}:${PGPORT}/${PGDATABASE} USER=${PGUSER}"
echo "[INFO] LDAP_URL=${LDAP_URL} (LDAPURI=${LDAPURI:-}) BASE_DN=${BASE_DN} (PEOPLE_OU=${PEOPLE_OU}) BIND_DN=${BIND_DN}"
if is_ldapi; then echo "[INFO] MODE=LOCAL(ldapi)"; else echo "[INFO] MODE=REMOTE(ldaps)"; fi
echo "[INFO] HOME_ROOT=${HOME_ROOT} SKEL=${SKEL_DIR} MODE=${HOME_MODE}"
echo "[INFO] DRY_RUN=${DRY_RUN:-false}"

# ===== コマンド確認 =====
need_cmd "$PHP_BIN"; need_cmd awk; need_cmd kakasi; need_cmd iconv
if command -v net >/dev/null 2>&1; then HAVE_NET=true; else HAVE_NET=false; fi

# ===== SID 取得 =====
if ! $HAVE_NET; then echo "[ERROR] 'net' command not found; Abort."; exit 1; fi
DOM_SID_RAW="$(net getdomainsid 2>/dev/null || true)"
DOM_SID_PREFIX="$(printf '%s\n' "$DOM_SID_RAW" | awk -F': ' '/[dD]omain/ {print $2}' | tr -d '[:space:]')"
if [[ -z "${DOM_SID_PREFIX}" ]]; then echo "[ERROR] Domain SID の抽出に失敗"; exit 1; fi
export DOM_SID_PREFIX
echo "[INFO] Domain SID: ${DOM_SID_PREFIX}"

# ===== スクリプト存在チェック =====
F1="${BASE_DIR}/ldap_id_pass_from_postgres_set.php"
F2="${BASE_DIR}/ldap_memberuid_users_group.php"
F3="${BASE_DIR}/ldap_memberuid_auto_group.sh"
F4="${BASE_DIR}/ldap_groupmap_smb_add.php"
F5="${BASE_DIR}/ldap_prune_home_dirs.php"
for f in "$F1" "$F2" "$F3" "$F4" "$F5"; do [[ -f "$f" ]] || { echo "[ERROR] missing file: $f"; exit 1; }; done

# ===== 実行ヘルパ =====
run_env() {
  local tool="$1"; shift || true
  echo "[RUN] (env) BIND_DN=${BIND_DN-} BIND_PW=$(mask_pw "${BIND_PW-}") ${PHP_BIN} ${tool} $*"
  CLICOLOR=1 CLICOLOR_FORCE=1 FORCE_COLOR=1 TERM="${TERM:-xterm-256color}" \
  BIND_DN="${BIND_DN-}" BIND_PW="${BIND_PW-}" \
  LDAP_URL="${PHP_LDAP_URL-}" LDAP_URI="${PHP_LDAP_URL-}" \
  LDAP_BASE_DN="${LDAP_BASE_DN-}" BASE_DN="${BASE_DN-}" PEOPLE_OU="ou=Users,${BASE_DN-}" \
  "${PHP_BIN}" "${tool}" "$@"
}
run_sh() {
  local tool="$1"; shift || true
  echo "[RUN] (env) BIND_DN=${BIND_DN-} BIND_PW=$(mask_pw "${BIND_PW-}") bash ${tool} $*"
  CLICOLOR=1 CLICOLOR_FORCE=1 FORCE_COLOR=1 TERM="${TERM:-xterm-256color}" \
  BIND_DN="${BIND_DN-}" BIND_PW="${BIND_PW-}" PHP_BIN="${PHP_BIN}" BASE_DIR="${BASE_DIR}" \
  LDAP_URL="${PHP_LDAP_URL-}" LDAP_URI="${PHP_LDAP_URL-}" LDAP_BASE_DN="${LDAP_BASE_DN-}" BASE_DN="${BASE_DN-}" \
  PEOPLE_OU="ou=Users,${BASE_DN-}" DRY_RUN="${DRY_RUN:-false}" \
  bash "${tool}" "$@"
}

# ===== 各STEP =====
step "STEP1: Import/Update users (homes + LDAP)"
run_env "$F1" --confirm --ldap --init
step_done "STEP1: Import/Update users (homes + LDAP)"

step "STEP2: Add all users to 'users' group (memberUid)"
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  run_env "$F2" --init
else
  run_env "$F2" --confirm --init
fi
step_done "STEP2: Add all users to 'users' group (memberUid)"

step "STEP3: Auto-assign groups by gidNumber (memberUid)"
run_sh "$F3"
step_done "STEP3: Auto-assign groups by gidNumber (memberUid)"

step "STEP4: Samba net groupmap add (posixGroup -> NT Domain group)"
if $HAVE_NET; then
  if [[ "${DRY_RUN:-false}" == "true" ]]; then
    run_env "$F4" --init
  else
    run_env "$F4" --confirm --init
  fi
else
  echo "[SKIP] 'net' command not found; skipping Samba groupmap"
fi
step_done "STEP4: Samba net groupmap add (posixGroup -> NT Domain group)"

step "STEP5: Prune home directories (+ LDAP delete)"
mkdir -p "$TRASH_DIR"
CMD5_OPTS=(--home-root="$HOME_ROOT" --trash="$TRASH_DIR" --log="${PRUNE_LOG:-/var/logs_share/prune_home_dirs.log}" --ldap-delete --ldap-url="$LDAP_URL" --ldap-base-dn="$LDAP_BASE_DN")

[[ -n "${AGE_DAYS:-}" ]] && CMD5_OPTS+=(--age-days="$AGE_DAYS")
[[ "${DRY_RUN:-false}" == "true" ]] || CMD5_OPTS+=(--confirm)
run_env "$F5" "${CMD5_OPTS[@]}"
step_done "STEP5: Prune home directories (+ LDAP delete)"

step "LOG ROTATE: delete old logs (>7 days)"
find "$LOG_DIR" -type f -name 'ldap_sync_*.log' -mtime +7 -print -delete
step_done "LOG ROTATE: delete old logs (>7 days)"

# ===== サマリ =====
echo
echo "==================== SUMMARY: STEP EXECUTION TIME ===================="
for s in "${!STEP_TIME_TOTAL[@]}"; do
  printf "  %-60s : %4ds\n" "$s" "${STEP_TIME_TOTAL[$s]}"
done | sort
echo "====================================================================="
echo "[INFO] done: $(date '+%F %T')"

