#!/bin/bash
# 4本のPHPを安全な順序で実行し、標準出力にも表示しつつ /root/logs に保存
# 1) PostgreSQL -> LDAP ユーザー投入/更新
# 2) 全ユーザーを users グループへ追加（memberUid）
# 3) gidNumber に基づき各グループへ追加（memberUid）
# 4) Samba groupmap 反映（net が無ければ自動スキップ）

set -Eeuo pipefail

# run_ldap_full_sync.sh 冒頭に追記
export PATH=/usr/local/bin:/usr/bin:/bin
export LANG=ja_JP.UTF-8
export LC_ALL=ja_JP.UTF-8

# ===== 設定 =====
BASE_DIR="/usr/local/etc/openldap/tools"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

# PostgreSQL 接続（.pgpass でも可。必要なら環境変数で上書き）
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}"
export PGDATABASE="${PGDATABASE:-accounting}"
# export PGPASSWORD="***"   # .pgpass を使うなら未設定でOK

# LDAP 管理者パスワード（環境変数があれば優先）※コードに平文を残さない運用推奨
export LDAP_ADMIN_PW="${LDAP_ADMIN_PW:-es0356525566}"

# STEP1 の対象列（3つ必須）
TARGET_COLUMNS="${TARGET_COLUMNS:-srv03 srv04 srv05}"

# ログ
LOG_DIR="/root/logs"
mkdir -p "$LOG_DIR"
TS="$(date '+%Y%m%d_%H%M%S')"
LOG_FILE="${LOG_DIR}/ldap_sync_${TS}.log"

# 標準出力＋ログ両方へ
exec > >(tee -a "$LOG_FILE") 2>&1

echo "[INFO] start: $(date '+%F %T')"
echo "[INFO] LOG_FILE: $LOG_FILE"
echo "[INFO] PG=${PGHOST}:${PGPORT}/${PGDATABASE}  USER=${PGUSER}"

# 依存コマンド
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERROR] command not found: $1"; exit 1; }; }
need_cmd "$PHP_BIN"
need_cmd awk
command -v net >/dev/null 2>&1 && HAVE_NET=true || HAVE_NET=false

# スクリプト存在チェック
F1="${BASE_DIR}/ldap_id_pass_from_postgres_set.php"
F2="${BASE_DIR}/ldap_memberuid_users_group.php"
F3="${BASE_DIR}/ldap_memberuid_auto_group.php"
F4="${BASE_DIR}/ldap_groupmap_smb_add.php"
for f in "$F1" "$F2" "$F3" "$F4"; do
  [[ -f "$f" ]] || { echo "[ERROR] missing file: $f"; exit 1; }
done

run() {
  echo "[RUN] $*"
  [[ "${DRY_RUN:-false}" == "true" ]] && return 0
  eval "$@"
}

section() { echo; echo "==================== $* ===================="; }

# ===== 実行 =====
section "STEP1: Import/Update users from PostgreSQL to LDAP"
COL1="$(echo "$TARGET_COLUMNS" | awk '{print $1}')"
COL2="$(echo "$TARGET_COLUMNS" | awk '{print $2}')"
COL3="$(echo "$TARGET_COLUMNS" | awk '{print $3}')"
[[ -n "$COL1" && -n "$COL2" && -n "$COL3" ]] || { echo "[ERROR] TARGET_COLUMNS must have 3 tokens: '$TARGET_COLUMNS'"; exit 1; }
run "$PHP_BIN '$F1' '$COL1' '$COL2' '$COL3'"

section "STEP2: Add all users to 'users' group (memberUid)"
run "$PHP_BIN '$F2'"

section "STEP3: Auto-assign groups by gidNumber (memberUid)"
run "$PHP_BIN '$F3'"

section "STEP4: Samba net groupmap add (posixGroup -> NT Domain group)"
if $HAVE_NET; then
  run "$PHP_BIN '$F4'"
else
  echo "[SKIP] 'net' command not found; skipping Samba groupmap"
fi

# 7日以上前のログを削除
section "LOG ROTATE: delete old logs (>7 days)"
find "$LOG_DIR" -type f -name 'ldap_sync_*.log' -mtime +7 -print -delete

echo "[INFO] done: $(date '+%F %T')"


