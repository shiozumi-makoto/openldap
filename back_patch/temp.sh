#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR='/usr/local/etc/openldap/tools'
cd "${BASE_DIR}"

echo
echo "=== START ACCOUNT UPDATE! (SAMBA+LDAP) ==="
echo
echo "${BASE_DIR}/temp.sh [実行shell]"
echo

# DRY-RUN 切替（DRY=1 なら --confirm を外す）
: "${DRY:=0}"

# 共通フラグ
LDAP_URI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'   # ldapi 推奨
COMMON_URI_FLAG=(--ldapi)                               # もしくは: --uri="${LDAP_URI}"
CONFIRM_FLAG=(--confirm)
[[ "$DRY" -eq 1 ]] && CONFIRM_FLAG=()
LIST_FLAG=(--list)


# echo -e "\n"
echo "★★★★★★[STEP1] ユーザ本体の同期（HOME/LDAP upsert）"
php "${BASE_DIR}/ldap_id_pass_from_postgres_set.php" --ldap --ldapi

echo "★★★★★★[STEP1.5]"

php "${BASE_DIR}/ldap_level_groups_sync.php" \
  --group=users,esmile-dev,nicori-dev,kindaka-dev,boj-dev,e_game-dev,solt-dev,social-dev,adm-cls,dir-cls,mgr-cls,mgs-cls,stf-cls,ent-cls,tmp-cls,err-cls \
  --init-group "${CONFIRM_FLAG[@]}" --ldapi \
  || true

echo "★★★★★★[STEP2] 役職クラスの posixGroup を事前整備（存在しなければ作成・gid整合）"
CLASS_GROUPS=(
  "adm-cls"
  "dir-cls"
  "mgr-cls"
  "mgs-cls"
  "stf-cls"
  "ent-cls"
  "tmp-cls"
  "err-cls"
)

echo "★★★★★★[STEP3] memberUid 同期（users / 開発系 / クラス群）"
TARGET_GROUPS_USERS=( "users" )
TARGET_GROUPS_CLASSES=( "${CLASS_GROUPS[@]}" )
TARGET_GROUPS_DEV=(
  "esmile-dev"
  "nicori-dev"
  "kindaka-dev"
  "boj-dev"
  "e_game-dev"
  "solt-dev"
  "social-dev"
)

# users
for g in "${TARGET_GROUPS_USERS[@]}"; do
  php "${BASE_DIR}/ldap_memberuid_users_group.php" \
    "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --init "${LIST_FLAG[@]}" --group="${g}"
done

# 開発系
for g in "${TARGET_GROUPS_DEV[@]}"; do
  php "${BASE_DIR}/ldap_memberuid_users_group.php" \
    "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --init "${LIST_FLAG[@]}" --group="${g}"
done

# 役職クラス
for g in "${TARGET_GROUPS_CLASSES[@]}"; do
  php "${BASE_DIR}/ldap_memberuid_users_group.php" \
    "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --init "${LIST_FLAG[@]}" --group="${g}"
done

echo
echo "★★★★★★[STEP4] Samba groupmap 同期（idempotent）"
php "${BASE_DIR}/ldap_smb_groupmap_sync.php"   "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --all --verbose

# オプション: 検証（あれば便利）
echo
net groupmap list | egrep 'users|dev|cls' || true

echo
echo "★★★★★★[STEP5] 不要ホームの整理"
php "${BASE_DIR}/prune_home_dirs.php"    "${COMMON_URI_FLAG[@]}"

echo "=== DONE ACCOUNT UPDATE! ==="
exit 0
