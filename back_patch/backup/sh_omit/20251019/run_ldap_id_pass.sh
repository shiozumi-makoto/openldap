#!/bin/bash
#============================================================
# LDAP/ホーム 同期ジョブ
# 1) PostgreSQL -> ホーム作成＆LDAPユーザー 追加/更新
# 2) 全ユーザーを 'users' グループへ追加（memberUid）
# 3) gidNumber に基づき各グループへ追加（memberUid）
# 4) Samba groupmap 反映
# 5) ホーム整理（退職/MISSING_DB を削除 or 退避）＋ LDAP アカウント削除
#============================================================

set -Eeuo pipefail

# ===== 環境 =====
export PATH="/usr/local/bin:/usr/bin:/bin"
export LANG="ja_JP.UTF-8"
export LC_ALL="ja_JP.UTF-8"

#==== force color for non-tty (tee) ====
export TERM="${TERM:-xterm-256color}"
export CLICOLOR=1
export CLICOLOR_FORCE="${CLICOLOR_FORCE:-1}"
export FORCE_COLOR="${FORCE_COLOR:-1}"
unset NO_COLOR

#==== 環境変数 ====
export LDAP_URI="${LDAP_URI:-ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi}"
export BASE_DN="${BASE_DN:-dc=e-smile,dc=ne,dc=jp}"
export PG="${PG:-/tmp:5432/accounting}"
export PGUSER="${PGUSER:-postgres}"
export HOME_ROOT="${HOME_ROOT:-/home}"
export SKEL="${SKEL:-/etc/skel}"

DRY_RUN=${DRY_RUN:-false}
VERBOSE=${VERBOSE:-0}
LIST=${LIST:-0}
INIT=${INIT:-0}
LOG_DIR="/root/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="${LOG_DIR}/ldap_id_pass_$(date +%Y%m%d_%H%M%S).log"

#==== 情報 ====
echo "[INFO] 開始: $(date '+%Y-%m-%d %H:%M:%S')"
echo "[INFO] LDAP_URI=$LDAP_URI"
echo "[INFO] BASE_DN=$BASE_DN"
echo "[INFO] PG=$PG USER=$PGUSER"
echo "[INFO] DRY_RUN=$DRY_RUN VERBOSE=$VERBOSE LIST=$LIST INIT=$INIT"
echo "[INFO] LOG_FILE=$LOG_FILE"
echo

#==== PHPスクリプト引数 ====
args=( "--ldap" "--ldapi" )
[ "$DRY_RUN" = "true" ] && args+=( "--dry-run" )
[ "$VERBOSE" = "1" ] && args+=( "--verbose" )
[ "$LIST" = "1" ] && args+=( "--list" )
[ "$INIT" = "1" ] && args+=( "--init" )

# ★ ldapi の URI を PHP に明示的に渡す
args+=( "--uri=${LDAP_URI}" )

#==== 実行 ====
php -d output_buffering=0 -d implicit_flush=1 \
  /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php \
  "${args[@]}" --confirm 2>&1 | tee "$LOG_FILE"

echo
echo "[INFO] 完了: $(date '+%Y-%m-%d %H:%M:%S')"


