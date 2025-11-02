#!/usr/bin/env bash
# ldap_memberuid_auto_group.sh
# 指定のグループ名で ldap_memberuid_users_group.php を順に実行するラッパー
# - DRY_RUN=true なら --confirm を付けない（安全側）
# - 接続は親から渡された PHP_LDAP_URL / LDAP_URL / LDAPURI を尊重
# - 明示的に --ldap を付与して PHP 側の LDAP 実行を強制

set -Eeuo pipefail
IFS=$'\n\t'
unset GROUPS || true  # bash 予約変数との衝突回避

: "${PHP_BIN:=/usr/bin/php}"
: "${BASE_DIR:=/usr/local/etc/openldap/tools}"
: "${LDAP_MODE:=ldaps}"   # 親が設定。無ければ ldaps 既定
: "${LDAPI_SOCK:=/usr/local/var/run/ldapi}"

TOOL="${BASE_DIR}/ldap_memberuid_users_group.php"
[[ -x "$PHP_BIN" ]] || { echo "[ERROR] PHP_BIN not executable: $PHP_BIN" >&2; exit 1; }
[[ -f "$TOOL"   ]] || { echo "[ERROR] not found: $TOOL" >&2; exit 1; }

# ===== 接続エンドポイントの整合 =====
# 親（run_ldap_full_sync.sh）が設定済みの PHP_LDAP_URL / LDAP_URL / LDAPURI を最優先で利用。
# 足りない場合に限って補完します。
case "${LDAP_MODE}" in
  ldaps)
    # 親が PHP_LDAP_URL/LDAP_URL をセット済み前提。無ければ補完。
    if [[ -z "${PHP_LDAP_URL:-}" && -n "${LDAP_URL:-}" ]]; then
      export PHP_LDAP_URL="${LDAP_URL}"
    fi
    if [[ -z "${PHP_LDAP_URL:-}" && -z "${LDAP_URL:-}" ]]; then
      host_fqdn="$(hostname -f 2>/dev/null || hostname)"
      export LDAP_URL="ldaps://${host_fqdn}"
      export PHP_LDAP_URL="${LDAP_URL}"
    fi
    # ldaps運用時は LDAPURI を無効化してフォールバックを防止
    unset LDAPURI LDAP_URI
    ;;
  ldapi)
    # 親が LDAPURI/PHP_LDAP_URL をセット済み前提。無ければ補完。
    if [[ -z "${LDAPURI:-}" && -n "${LDAPI_SOCK:-}" ]]; then
      p="${LDAPI_SOCK#/}"; p="${p//\//%2F}"
      export LDAPURI="ldapi://%2F${p}"
    fi
    if [[ -z "${PHP_LDAP_URL:-}" ]]; then
      export PHP_LDAP_URL="${LDAPURI}"
    fi
    ;;
  *)
    echo "[ERROR] invalid LDAP_MODE=${LDAP_MODE} (must be ldaps or ldapi)" >&2
    exit 2
    ;;
esac

# ===== DRY-RUN / CONFIRM =====
declare -a COMMON_OPTS
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  # 破壊的操作をしない
  COMMON_OPTS=( --ldap --init --list )
else
  COMMON_OPTS=( --ldap --confirm --init --list )
fi

# ===== 対象グループ =====
declare -a TARGET_GROUPS
if [[ -n "${TARGET_GROUPS_CSV:-}" ]]; then
  IFS=',' read -r -a TARGET_GROUPS <<< "${TARGET_GROUPS_CSV}"
else
  TARGET_GROUPS=(
    "esmile-dev"
    "nicori-dev"
    "kindaka-dev"
    "boj-dev"
    "e_game-dev"
    "solt-dev"
    "social-dev"
  )
fi

# ===== 実行関数 =====
run_one() {
  local grp="${1:-}"
  [[ -n "$grp" ]] || { echo "[WARN] empty group; skip" >&2; return 0; }

  echo "[RUN] ${PHP_BIN} ${TOOL} ${COMMON_OPTS[*]} --group=${grp}"
  # 必要な環境変数だけを明示して継承
  env \
    PHP_LDAP_URL="${PHP_LDAP_URL-}" \
    LDAP_URL="${LDAP_URL-}" \
    LDAPURI="${LDAPURI-}" \
    BASE_DN="${BASE_DN-}" \
    PEOPLE_OU="${PEOPLE_OU-}" \
    BIND_DN="${BIND_DN-}" \
    BIND_PW="${BIND_PW-}" \
    "${PHP_BIN}" "${TOOL}" "${COMMON_OPTS[@]}" --group="${grp}"
}

for g in "${TARGET_GROUPS[@]}"; do
  run_one "$g"
done

echo "[INFO] ldap_memberuid_auto_group.sh done."


