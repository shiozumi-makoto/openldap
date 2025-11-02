#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR='/usr/local/etc/openldap/tools'
cd "${BASE_DIR}"

echo
echo "=== START ACCOUNT UPDATE! (SAMBA+LDAP) ==="
echo
echo "${BASE_DIR}/temp.sh [実行shell]"
echo

# 共通フラグ
LDAP_URI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'   # ldapi 推奨
COMMON_URI_FLAG=(--ldapi)                               # もしくは: --uri="${LDAP_URI}"
CONFIRM_FLAG=(--confirm)
LIST_FLAG=(--list)

# 1) ユーザ本体の同期（HOME/LDAP upsert）
php "${BASE_DIR}/ldap_id_pass_from_postgres_set.php" --ldap --ldapi

# 2) 役職クラスの posixGroup を事前に用意（存在しなければ作成・gid整合）
#    GroupDef が無い環境でも修正版 ldap_level_groups_sync.php ならローカル定義で補完します

CLASS_GROUPS=(
	"adm-cls"
	"dir-cls"
	"mgr-cls"
	"mgs-cls"
	"stf-cls"
	"ent-cls"
	"tmp-cls err-cls"
)

php "${BASE_DIR}/ldap_level_groups_sync.php" \
  --init-group "${CONFIRM_FLAG[@]}" --ldap-uri="${LDAP_URI}" \
  || true   # 既存でもOK / 失敗しても後続で --init 付き同期が再挑戦

# 3) memberUid 同期（users / クラス群 / 開発系）
#    ※ --init を付けるので、未作成グループがあっても自動で作られます
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

# 4) Samba groupmap（必要に応じて --force）
php "${BASE_DIR}/ldap_groupmap_smb_add.php"   "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --force

# 5) 不要ホームの整理（DRY-RUNにしたい場合はこのスクリプト側のオプションに合わせて調整）
php "${BASE_DIR}/ldap_prune_home_dirs.php"    "${COMMON_URI_FLAG[@]}"

echo
echo "=== DONE ACCOUNT UPDATE! ==="
exit 0
