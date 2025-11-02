#!/bin/bash
# LDAP/ホーム 同期フルジョブ
# 1) PostgreSQL -> ホーム作成＆LDAPユーザー 追加/更新（kakasi uid, /home/%02d-%03d-%s）
# 2) 全ユーザーを 'users' グループへ追加（memberUid）
# 3) gidNumber に基づき各グループへ追加（memberUid）
# 4) Samba groupmap 反映（net が無ければスキップ）
# 5) ホーム整理（退職/MISSING_DB を削除 or 退避）＋ LDAP アカウント削除（任意）

set -Eeuo pipefail

# ===== 環境 =====
export PATH=/usr/local/bin:/usr/bin:/bin
export LANG=ja_JP.UTF-8
export LC_ALL=ja_JP.UTF-8

# ===== 設定 =====
BASE_DIR="/usr/local/etc/openldap/tools/tools_backup"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

# PostgreSQL 接続（.pgpass でも可）
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}"
export PGDATABASE="${PGDATABASE:-accounting}"
# export PGPASSWORD="***"   # .pgpass を使うなら未設定でOK（~/.pgpass 600）

# LDAP 接続（bind パスワードは環境変数で受け取る推奨）
LDAP_URL="${LDAP_URL:-ldap://127.0.0.1}"
LDAP_BASE_DN="${LDAP_BASE_DN:-ou=Users,dc=e-smile,dc=ne,dc=jp}"
BIND_DN="${BIND_DN:-cn=Admin,dc=e-smile,dc=ne,dc=jp}"
LDAP_ADMIN_PW="${LDAP_ADMIN_PW:-}"  # 空なら匿名bind（必要に応じて export して渡してください）

# ホーム作成の既定
HOME_ROOT="${HOME_ROOT:-/home}"
SKEL_DIR="${SKEL_DIR:-/etc/skel}"
HOME_MODE="${HOME_MODE:-0750}"

# prune（整理）の既定
TRASH_DIR="${TRASH_DIR:-/var/tmp/home_trash}"   # 退避先（存在しなければ作成）
AGE_DAYS="${AGE_DAYS:-}"                        # 例: 14 を入れると14日以内更新のホームは削除保留

# LDAP posixAccount 付与方針（ldap_id_pass_from_postgres_set.php）
# auto: NSS で uid/gid 取得できたら付与（安全） / off: 付けない / force: 常に付与
LDAP_POSIX="${LDAP_POSIX:-auto}"
GID_DEFAULT="${GID_DEFAULT:-100}"
LOGIN_SHELL="${LOGIN_SHELL:-/bin/bash}"

# ログ
LOG_DIR="${LOG_DIR:-/root/logs}"
mkdir -p "$LOG_DIR"
TS="$(date '+%Y%m%d_%H%M%S')"
LOG_FILE="${LOG_DIR}/ldap_sync_${TS}.log"
ADD_LOG="${ADD_LOG:-/var/logs_share/add_home_from_db.log}"
PRUNE_LOG="${PRUNE_LOG:-/var/logs_share/prune_home_dirs.log}"
mkdir -p "$(dirname "$ADD_LOG")" "$(dirname "$PRUNE_LOG")"

# 標準出力＋ログ両方へ
exec > >(tee -a "$LOG_FILE") 2>&1

echo "[INFO] start: $(date '+%F %T')"
echo "[INFO] LOG_FILE: $LOG_FILE"
echo "[INFO] PG=${PGHOST}:${PGPORT}/${PGDATABASE} USER=${PGUSER}"
echo "[INFO] LDAP=${LDAP_URL} BASE_DN=${LDAP_BASE_DN} BIND_DN=${BIND_DN}"
echo "[INFO] HOME_ROOT=${HOME_ROOT} SKEL=${SKEL_DIR} MODE=${HOME_MODE}"
echo "[INFO] DRY_RUN=${DRY_RUN:-false}"

# ===== 依存コマンド =====
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERROR] command not found: $1"; exit 1; }; }
need_cmd "$PHP_BIN"
need_cmd awk
need_cmd kakasi
need_cmd iconv
command -v net >/dev/null 2>&1 && HAVE_NET=true || HAVE_NET=false

# ===== スクリプト存在チェック =====
F1="${BASE_DIR}/ldap_id_pass_from_postgres_set.php"
F2="${BASE_DIR}/ldap_memberuid_users_group.php"
F3="${BASE_DIR}/ldap_memberuid_auto_group.php"
F4="${BASE_DIR}/ldap_groupmap_smb_add.php"
F5="${BASE_DIR}/ldap_prune_home_dirs.php"
for f in "$F1" "$F2" "$F3" "$F4" "$F5"; do
  [[ -f "$f" ]] || { echo "[ERROR] missing file: $f"; exit 1; }
done

run() {
  echo "[RUN] $*"
  if [[ "${DRY_RUN:-false}" == "true" ]]; then
    # DRY時は --confirm を落として実行（あれば）
    # shellcheck disable=SC2001
    local cmd
    cmd="$(echo "$*" | sed 's/ --confirm//g')"
    eval "$cmd"
  else
    eval "$@"
  fi
}

section() { echo; echo "==================== $* ===================="; }

# ===== STEP1: PostgreSQL -> ホーム作成 & LDAPユーザー 追加/更新 =====
#   ※ php 側のホスト制限（ovs-010 / ovs-012）でガードされます
section "STEP1: Import/Update users (homes + LDAP)"
CMD1=( "$PHP_BIN" "$F1"
  --home-root="$HOME_ROOT"
  --skel="$SKEL_DIR"
  --mode="$HOME_MODE"
  --log="$ADD_LOG"
  --ldap-enable
  --ldap-url="$LDAP_URL"
  --ldap-base-dn="$LDAP_BASE_DN"
  --bind-dn="$BIND_DN"
  --bind-pass="${LDAP_ADMIN_PW}"
  --ldap-posix="$LDAP_POSIX"
  --gid-default="$GID_DEFAULT"
  --login-shell="$LOGIN_SHELL"
)
# 実行時のみ --confirm を付与
[[ "${DRY_RUN:-false}" == "true" ]] || CMD1+=( --confirm )
run "${CMD1[@]}"

# ===== STEP2: 全ユーザーを 'users' グループへ追加（memberUid） =====
section "STEP2: Add all users to 'users' group (memberUid)"
run "$PHP_BIN" "$F2"

# ===== STEP3: gidNumber に基づき各グループへ追加（memberUid） =====
section "STEP3: Auto-assign groups by gidNumber (memberUid)"
run "$PHP_BIN" "$F3"

# ===== STEP4: Samba groupmap 反映 =====
section "STEP4: Samba net groupmap add (posixGroup -> NT Domain group)"
if $HAVE_NET; then
  run "$PHP_BIN" "$F4"
else
  echo "[SKIP] 'net' command not found; skipping Samba groupmap"
fi

# ===== STEP5: ホーム整理（退避推奨）＋ LDAP アカウント削除 =====
section "STEP5: Prune home directories (+ LDAP delete)"
mkdir -p "$TRASH_DIR"
CMD5=( "$PHP_BIN" "$F5"
  --home-root="$HOME_ROOT"
  --trash="$TRASH_DIR"
  --log="$PRUNE_LOG"
  --ldap-delete
  --ldap-url="$LDAP_URL"
  --ldap-base-dn="$LDAP_BASE_DN"
  --bind-dn="$BIND_DN"
  --bind-pass="${LDAP_ADMIN_PW}"
)
# 年齢フィルタ（省略可）
[[ -n "${AGE_DAYS}" ]] && CMD5+=( --age-days="$AGE_DAYS" )
# 実行時のみ --confirm を付与
[[ "${DRY_RUN:-false}" == "true" ]] || CMD5+=( --confirm )
run "${CMD5[@]}"

# ===== ログのローテーション =====
section "LOG ROTATE: delete old logs (>7 days)"
find "$LOG_DIR" -type f -name 'ldap_sync_*.log' -mtime +7 -print -delete

echo "[INFO] done: $(date '+%F %T')"


