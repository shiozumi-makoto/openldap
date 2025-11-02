#!/usr/bin/env bash
# ldap_memberuid_auto_group.sh
# 指定のグループ名で ldap_memberuid_users_group.php を順に実行するラッパー
# - DRY_RUN=true なら --confirm を付けない
# - それ以外は --confirm を付ける

set -Eeuo pipefail
IFS=$'\n\t'
unset GROUPS || true  # bash特殊変数 GROUPS と衝突回避

: "${PHP_BIN:=/usr/bin/php}"
: "${BASE_DIR:=/usr/local/etc/openldap/tools}"

TOOL="${BASE_DIR}/ldap_memberuid_users_group.php"
[[ -x "$PHP_BIN" ]] || { echo "[ERROR] PHP_BIN not executable: $PHP_BIN" >&2; exit 1; }
[[ -f "$TOOL"   ]] || { echo "[ERROR] not found: $TOOL" >&2; exit 1; }

# DRY_RUN 切替
declare -a CONF_OPTS
if [[ "${DRY_RUN:-false}" == "true" ]]; then
#  CONF_OPTS=( --init --list )
   CONF_OPTS=( )
else
  CONF_OPTS=( --confirm --init --list )
fi
join_conf_opts() { local o=""; for x in "${CONF_OPTS[@]}"; do o+="${x} "; done; printf '%s' "${o%% }"; }

# 実行対象グループ（CSV で上書き可）
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

run_one() {
  local grp="${1:-}"
  [[ -n "$grp" ]] || { echo "[WARN] empty group; skip" >&2; return 0; }
  echo "[RUN] ${PHP_BIN} ${TOOL} $(join_conf_opts) --group=${grp}"
  BIND_DN="${BIND_DN-}" BIND_PW="${BIND_PW-}" \
  LDAP_URL="${LDAP_URL-}" LDAP_URI="${LDAP_URI-}" \
  LDAP_BASE_DN="${LDAP_BASE_DN-}" BASE_DN="${BASE_DN-}" \
  PEOPLE_OU="${PEOPLE_OU-}" \
  "${PHP_BIN}" "${TOOL}" "${CONF_OPTS[@]}" --group="${grp}"
}

for g in "${TARGET_GROUPS[@]}"; do
  run_one "$g"
done

echo "[INFO] ldap_memberuid_auto_group.sh done."


