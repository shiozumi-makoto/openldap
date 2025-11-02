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

# ===== 設定 =====
BASE_DIR="/usr/local/etc/openldap/tools"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

# PostgreSQL（.pgpass でもOK）
export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-postgres}"
export PGDATABASE="${PGDATABASE:-accounting}"
# export PGPASSWORD="${PGPASSWORD:-}"

# ===== LDAP接続先 自動判定 =====
# 自ホストが ovs-012（FQDN: ovs-012.e-smile.local）なら ldapi、
# それ以外のホストから実行されたら ldaps://ovs-012.e-smile.local を使う
HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
HOST_SHORT="$(hostname -s 2>/dev/null || echo "")"
LDAP_URL_DEFAULT="ldaps://ovs-012.e-smile.local"
if [[ "$HOST_SHORT" == "ovs-012" || "$HOST_FQDN" == "ovs-012.e-smile.local" ]]; then
  LDAP_URL_DEFAULT='ldapi://%2Fvar%2Frun%2Fldapi'
fi
export LDAP_URL="${LDAP_URL:-$LDAP_URL_DEFAULT}"

# PHPツールは ldapi に弱い環境があるため、ローカルでも PHP 向けは ldaps にフォールバック
if [[ "$LDAP_URL" == ldapi://* ]]; then
  PHP_LDAP_URL="ldaps://ovs-012.e-smile.local"
else
  PHP_LDAP_URL="$LDAP_URL"
fi
export PHP_LDAP_URL
export LDAP_BASE_DN="${LDAP_BASE_DN:-ou=Users,dc=e-smile,dc=ne,dc=jp}"
export BIND_DN="${BIND_DN:-cn=Admin,dc=e-smile,dc=ne,dc=jp}"

# === (A) 先頭の設定群の少し下あたりに追記 ==============================

# LDAPURI の既定（未設定で /var/run/ldapi があれば ldapi を優先）
if [[ -z "${LDAPURI:-}" ]]; then
  if [[ -S /var/run/ldapi ]]; then
    export LDAPURI='ldapi://%2Fvar%2Frun%2Fldapi'
  else
    # ここは環境に合わせて。既定を ldaps にしたい場合は↓を有効化
    # export LDAPURI="ldaps://$(hostname -f)"
    :
  fi
fi

is_ldapi() { [[ "${LDAPURI}" == ldapi://* ]]; }

# 認証フラグ生成（ldapi→EXTERNAL / それ以外→simple bind）
ldap_auth_flags() {
  if is_ldapi; then
    # ldapi + EXTERNAL は root 実行が前提（slapd の socket に対して特権で認証）
    echo "-H ${LDAPURI} -Y EXTERNAL -Q"
  else
    # simple bind
    local dn pw
    dn="${BIND_DN:-${LDAP_BIND_DN:-}}"
    pw="${BIND_PW:-${LDAP_ADMIN_PW:-}}"
    if [[ -z "$dn" || -z "$pw" ]]; then
      echo "[ERROR] BIND_DN/BIND_PW が未設定です（ldaps では必須）" >&2
      return 2
    fi
    echo "-H ${LDAPURI} -D ${dn} -w ${pw}"
  fi
}

# ldap コマンド薄ラッパ（wrap/no を標準で付ける）
ldap_do() {
  # 使い方: ldap_do search -b "dc=example,dc=com" "(objectClass=*)"
  local subcmd="$1"; shift
  local auth; auth="$(ldap_auth_flags)" || return $?
  case "$subcmd" in
    search)  ldapsearch  -o ldif-wrap=no ${auth} "$@";;
    modify)  ldapmodify  -o ldif-wrap=no ${auth} "$@";;
    add)     ldapadd     -o ldif-wrap=no ${auth} "$@";;
    delete)  ldapdelete                ${auth} "$@";;
    *) echo "[BUG] unknown ldap subcmd: $subcmd" >&2; return 3;;
  esac
}

# === (B) 既存の「BIND_PW が未設定ならエラー」チェックを置換 =============

# 旧: if [[ -z "${BIND_PW:-${LDAP_ADMIN_PW:-}}" ]]; then ... fi
# 新: ldapi でない時のみチェック
if ! is_ldapi; then
  if [[ -z "${BIND_DN:-${LDAP_BIND_DN:-}}" ]]; then
    echo "[ERROR] BIND_DN が未設定です（ldaps では必須）" >&2
    exit 1
  fi
  if [[ -z "${BIND_PW:-${LDAP_ADMIN_PW:-}${BIND_PW_FILE:+x}}" ]]; then
    # BIND_PW_FILE があれば後段で読み込む想定でも OK
    echo "[ERROR] BIND_PW が未設定です（BIND_PW / LDAP_ADMIN_PW / BIND_PW_FILE のいずれかが必要）" >&2
    exit 1
  fi
fi

# === (C) 実処理部の ldap コマンド呼び出しを書き換え =====================

# 例1: 参照
# 旧: ldapsearch -x -H "$LDAPURI" -D "$BIND_DN" -w "$BIND_PW" -b "$BASE" ...
# 新:
# ldap_do search -x -b "$BASE_DN" "(objectClass=posixAccount)" dn || exit 1

# 例2: 追加
# 旧: ldapadd -x -H "$LDAPURI" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/add.ldif
# 新:
# ldap_do add -x -f /tmp/add.ldif || exit 1

# 例3: 変更
# 旧: ldapmodify -x -H "$LDAPURI" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/modify.ldif
# 新:
# ldap_do modify -x -f /tmp/modify.ldif || exit 1

# echo $LDAP_BASE_DN
# echo $BIND_DN
# exit;
# exit


# ---- 互換用（ツールが getenv 参照する場合の保険）----
export LDAP_URI="${LDAP_URI:-$LDAP_URL}"
BASE_DN_DERIVED="${LDAP_BASE_DN#ou=Users,}"
export BASE_DN="${BASE_DN:-$BASE_DN_DERIVED}"
export PEOPLE_OU="${PEOPLE_OU:-ou=Users,${BASE_DN}}"

# ホーム既定
export HOME_ROOT="${HOME_ROOT:-/home}"
export SKEL_DIR="${SKEL_DIR:-/etc/skel}"
export HOME_MODE="${HOME_MODE:-0750}"

# prune 既定
export TRASH_DIR="${TRASH_DIR:-/var/tmp/home_trash}"
export AGE_DAYS="${AGE_DAYS:-}"

# posixAccount 方針
export LDAP_POSIX="${LDAP_POSIX:-auto}"
export GID_DEFAULT="${GID_DEFAULT:-100}"
export LOGIN_SHELL="${LOGIN_SHELL:-/bin/bash}"

# ログ設定
export LOG_DIR="${LOG_DIR:-/root/logs}"
mkdir -p "$LOG_DIR"
TS="$(date '+%Y%m%d_%H%M%S')"
LOG_FILE="${LOG_DIR}/ldap_sync_${TS}.log"
export ADD_LOG="${ADD_LOG:-/var/logs_share/add_home_from_db.log}"
export PRUNE_LOG="${PRUNE_LOG:-/var/logs_share/prune_home_dirs.log}"
mkdir -p "$(dirname "$ADD_LOG")" "$(dirname "$PRUNE_LOG")"

# 標準出力＋ファイルへ
exec > >(tee -a "$LOG_FILE") 2>&1

mask_pw() { [ -n "${1-}" ] && printf '%s' '********' || printf '%s' ''; }
section() { echo; echo "==================== $* ===================="; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERROR] command not found: $1"; exit 1; }; }

echo "[INFO] start: $(date '+%F %T')"
echo "[INFO] LOG_FILE: $LOG_FILE"
echo "[INFO] PG=${PGHOST}:${PGPORT}/${PGDATABASE} USER=${PGUSER}"
echo "[INFO] LDAP=${LDAP_URL} BASE_DN=${BASE_DN} (PEOPLE_OU=${PEOPLE_OU}) BIND_DN=${BIND_DN}"
if [[ "$LDAP_URL" == ldapi://* ]]; then
  echo "[INFO] MODE=LOCAL(ldapi)"
else
  echo "[INFO] MODE=REMOTE(ldaps)"
fi
echo "[INFO] HOME_ROOT=${HOME_ROOT} SKEL=${SKEL_DIR} MODE=${HOME_MODE}"
echo "[INFO] DRY_RUN=${DRY_RUN:-false}"

# 依存コマンド確認
need_cmd "$PHP_BIN"
need_cmd awk
need_cmd kakasi
need_cmd iconv
if command -v net >/dev/null 2>&1; then
  HAVE_NET=true
else
  HAVE_NET=false
fi

# ===== Samba Domain SID 自動取得 =====
if ! $HAVE_NET; then
  echo "[ERROR] 'net' command not found; cannot get Domain SID. Abort."
  exit 1
fi
DOM_SID_RAW="$(net getdomainsid 2>/dev/null || true)"
if ! printf '%s\n' "$DOM_SID_RAW" | grep -qiE '\bE-?SMILE\b'; then
  echo "[ERROR] 'net getdomainsid' の結果に E-SMILE ドメインが見つかりません。中断します。"
  echo "  出力: $DOM_SID_RAW"
  exit 1
fi
DOM_SID_PREFIX="$(printf '%s\n' "$DOM_SID_RAW" | awk -F': ' '/[dD]omain/ {print $2}' | tr -d '[:space:]')"
if [[ -z "${DOM_SID_PREFIX}" ]]; then
  echo "[ERROR] Domain SID の抽出に失敗しました。中断します。"
  echo "  出力: $DOM_SID_RAW"
  exit 1
fi
export DOM_SID_PREFIX
echo "[INFO] Domain SID: ${DOM_SID_PREFIX}"

# ===== スクリプト存在チェック =====
F1="${BASE_DIR}/ldap_id_pass_from_postgres_set.php"
F2="${BASE_DIR}/ldap_memberuid_users_group.php"
F3="${BASE_DIR}/ldap_memberuid_auto_group.sh"   # .sh ラッパー
F4="${BASE_DIR}/ldap_groupmap_smb_add.php"
F5="${BASE_DIR}/ldap_prune_home_dirs.php"
for f in "$F1" "$F2" "$F3" "$F4" "$F5"; do
  [[ -f "$f" ]] || { echo "[ERROR] missing file: $f"; exit 1; }
done

# ===== 実行ヘルパ =====
# PHPツール（環境変数注入）
run_env() {
  local tool="$1"; shift || true
  echo "[RUN] (env) BIND_DN=${BIND_DN} BIND_PW=$(mask_pw "$BIND_PW") $PHP_BIN $tool $*"
  BIND_DN="$BIND_DN" BIND_PW="$BIND_PW" \
  LDAP_URL="$PHP_LDAP_URL" LDAP_URI="$PHP_LDAP_URL" \
  LDAP_BASE_DN="$LDAP_BASE_DN" BASE_DN="$BASE_DN" \
  PEOPLE_OU="ou=Users,${BASE_DN}" \
  "$PHP_BIN" "$tool" "$@"
}
# シェルラッパー（環境変数注入／DRY_RUNをそのまま伝播）
run_sh() {
  local tool="$1"; shift || true
  echo "[RUN] (env) BIND_DN=${BIND_DN} BIND_PW=$(mask_pw "$BIND_PW") bash $tool $*"
  BIND_DN="$BIND_DN" BIND_PW="$BIND_PW" \
  PHP_BIN="$PHP_BIN" BASE_DIR="$BASE_DIR" \
  LDAP_URL="$PHP_LDAP_URL" LDAP_URI="$PHP_LDAP_URL" \
  LDAP_BASE_DN="$LDAP_BASE_DN" BASE_DN="$BASE_DN" \
  PEOPLE_OU="ou=Users,${BASE_DN}" \
  DRY_RUN="${DRY_RUN:-false}" \
  bash "$tool" "$@"
}

# ===== STEP1: PostgreSQL -> ホーム作成 & LDAPユーザー 追加/更新 =====
section "STEP1: Import/Update users (homes + LDAP)"
# 再構築モード: --confirm --ldap --init を付与
run_env "$F1" --confirm --ldap --init

# ===== STEP2: 全ユーザーを 'users' グループへ追加（memberUid） =====
section "STEP2: Add all users to 'users' group (memberUid)"
# ここは常に --init を付ける（confirm は DRY_RUN に追従）
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  run_env "$F2" --init
else
  run_env "$F2" --confirm --init
fi

# ===== STEP3: gidNumber に基づき各グループへ追加（memberUid） =====
section "STEP3: Auto-assign groups by gidNumber (memberUid)"
# .sh 側が --init を付け、DRY_RUN=true なら --confirm を外す実装
run_sh "$F3"

# ===== STEP4: Samba groupmap 反映 =====
section "STEP4: Samba net groupmap add (posixGroup -> NT Domain group)"
if $HAVE_NET; then
  if [[ "${DRY_RUN:-false}" == "true" ]]; then
    run_env "$F4" --init
  else
    run_env "$F4" --confirm --init
  fi
else
  echo "[SKIP] 'net' command not found; skipping Samba groupmap"
fi

# ===== STEP5: ホーム整理（退職/MISSING_DB を削除 or 退避）＋ LDAP アカウント削除 =====
section "STEP5: Prune home directories (+ LDAP delete)"
mkdir -p "$TRASH_DIR"
CMD5_OPTS=(
  --home-root="$HOME_ROOT"
  --trash="$TRASH_DIR"
  --log="$PRUNE_LOG"
  --ldap-delete
  --ldap-url="$LDAP_URL"
  --ldap-base-dn="$LDAP_BASE_DN"
)
[[ -n "${AGE_DAYS}" ]] && CMD5_OPTS+=( --age-days="$AGE_DAYS" )
[[ "${DRY_RUN:-false}" == "true" ]] || CMD5_OPTS+=( --confirm )
run_env "$F5" "${CMD5_OPTS[@]}"

# ===== ログのローテーション =====
section "LOG ROTATE: delete old logs (>7 days)"
find "$LOG_DIR" -type f -name 'ldap_sync_*.log' -mtime +7 -print -delete

echo "[INFO] done: $(date '+%F %T')"


