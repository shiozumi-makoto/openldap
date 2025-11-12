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
COMMON_URI_FLAG_ORG=(--ldapi --base-dn='dc=e-smile,dc=ne,dc=jp')                               # もしくは: --uri="${LDAP_URI}"
COMMON_URI_FLAG=(--uri='ldaps://ovs-012.e-smile.local:636' --bind-dn='cn=Admin,dc=e-smile,dc=ne,dc=jp' --bind-pass='es0356525566' --base-dn='dc=e-smile,dc=ne,dc=jp')
CONFIRM_FLAG=(--confirm)
[[ "$DRY" -eq 1 ]] && CONFIRM_FLAG=()
LIST_FLAG=(--list)

# kakasi 
export PATH=/usr/local/bin:$PATH

# exit
# echo $PATH

echo "-------------------------------------------------------------------------------------- ★[STEP1] ユーザ本体の同期（HOME/LDAP upsert）"
php "${BASE_DIR}/ldap_id_pass_from_postgres_set.php" --ldapi --ldap --confirm


#
# ovs-009 もついでに？
# php ldap_id_pass_from_postgres_set.php --ldap --confirm --ldaps --uri=ldaps://ovs-009.e-smile.local:636 --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp" --bind-pass='es0356525566' --base-dn="dc=e-smile,dc=ne,dc=jp"
#
# PATH=/usr/bin:/bin: /bin/sh -c '/usr/local/etc/openldap/tools/temp.sh'
# env -i PATH=/usr/local/bin:/usr/bin:/bin: HOME=/root SHELL=/bin/sh /bin/sh -c '/usr/local/etc/openldap/tools/temp.sh'
# php ldap_id_pass_from_postgres_set.php --ldapi --home --maildir-only --confirm
# php ldap_id_pass_from_postgres_set.php --ldapi --ldap --confirm
# php ldap_id_pass_from_postgres_set.php --ldapi --ldap --cmps=5 --users=101 --confirm
# ldapsearch -x -b "ou=Users,dc=e-smile,dc=ne,dc=jp" "(uid=takahashi-ryouya2)"
# exit
# Up!  [250] [12-209] [uid: 120209 gid: 2012] [ent-cls 20] [inagaki-wanoka      ] [CON] 更新 [nvEiGQ3g_5] [稲垣 和乃華]


echo "-------------------------------------------------------------------------------------- ★☆[STEP1.5]"
#
# php ldap_level_groups_sync.php --init-group --ldapi --description --group=users,esmile-dev,err-cls --confirm
# export MAIL_PRIMARY_DOMAIN=esmile-holdings.com
# export MAIL_EXTRA_DOMAINS="esmile-soltribe.com, esmile-systems.jp"
#
# BASE_DN   : dc=e-smile,dc=ne,dc=jp
# GROUPS_OU : ou=Groups,dc=e-smile,dc=ne,dc=jp
#

# echo ldap_level_groups_sync.php --group=users,esmile-dev,nicori-dev,kindaka-dev,boj-dev,e_game-dev,solt-dev,social-dev,adm-cls,dir-cls,mgr-cls,mgs-cls,stf-cls,ent-cls,tmp-cls,err-cls --init-group "${CONFIRM_FLAG[@]}" "${COMMON_URI_FLAG_ORG[@]}" --description || true

php "${BASE_DIR}/ldap_level_groups_sync.php" \
  --group=users,esmile-dev,nicori-dev,kindaka-dev,boj-dev,e_game-dev,solt-dev,social-dev,adm-cls,dir-cls,mgr-cls,mgs-cls,stf-cls,ent-cls,tmp-cls,err-cls \
  --init-group "${CONFIRM_FLAG[@]}" "${COMMON_URI_FLAG_ORG[@]}" --description \
  || true


echo "-------------------------------------------------------------------------------------- ★★[STEP2] 役職クラスの posixGroup を事前整備（存在しなければ作成・gid整合）"
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

echo "-------------------------------------------------------------------------------------- ★★★[STEP3] memberUid 同期（users / 開発系 / クラス群）"
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




echo "-------------------------------------------------------------------------------------- ★★★★[STEP4] Samba groupmap 同期（idempotent）"
php "${BASE_DIR}/ldap_smb_groupmap_sync.php"   "${COMMON_URI_FLAG[@]}" "${CONFIRM_FLAG[@]}" --all --verbose

# オプション: 検証（あれば便利）
echo
net groupmap list | egrep 'users|dev|cls' || true

# ssh ovs-009 "net groupmap list | egrep 'users|dev|cls' || true"

echo "-------------------------------------------------------------------------------------- ★★★★★[STEP5] 不要ホームの整理"
php "${BASE_DIR}/prune_home_dirs.php" "${COMMON_URI_FLAG[@]}"
echo "=== DONE ACCOUNT UPDATE! ==="

# ------------------------------------
# php sync_mail_extension_from_ldap.php --confirm --P --pg-post=ovs-010
# php sync_mail_extension_from_ldap.php --confirm --U --pg-post=ovs-010
# php sync_mail_extension_from_ldap.php --confirm --O --pg-post=ovs-010
# php make_forward_from_pg.php --confirm
#
# php sync_mail_extension_from_ldap.php --confirm --O --pg-host=ovs-010
#
# --P --U :ldap の mail ( --P = people / --U = users )
# --O	  :passwd_mail の login_id 列を参照！
#
# php sync_mail_extension_from_ldap.php --ldapi --O --pg-post=ovs-010
# ------------------------------------

echo "-------------------------------------------------------------------------------------- ★★★★★★[STEP6] .forward 情報個人メール拡張の更新"
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --P --pg-post=ovs-010
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --U --pg-post=ovs-010
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --O --pg-post=ovs-010

echo "-------------------------------------------------------------------------------------- ★★★★★★[STEP6 + plus] .forward のファイル作成"
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --P --pg-post=ovs-012
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --U --pg-post=ovs-012
php "${BASE_DIR}/sync_mail_extension_from_ldap.php" "${COMMON_URI_FLAG[@]}" --O --pg-post=ovs-012
php "${BASE_DIR}/make_forward_from_pg.php" "${COMMON_URI_FLAG[@]}"

echo "-------------------------------------------------------------------------------------- ★★★★★★★[STEP7] MailBoxの容量チェック"
echo "Maildir の削除"
php prune_maildir.php --maildir --maildir-days=180 --maildir-max-count=80000 --maildir-max-size=2000MB


echo "-------------------------------------------------------------------------------------- End ! "
exit
