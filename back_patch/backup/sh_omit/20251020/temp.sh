#!/usr/bin/env bash
set -Eeuo pipefail
BASE_DIR='/usr/local/etc/openldap/tools/'
cd $BASE_DIR
# echo $BASE_DIR

echo ""
echo "=== START ACCOUNT UPDATE! (SAMBA+LDAP) ==="
echo ""
echo "${BASE_DIR}/temp.sh [実行shell]"
echo ""
#exit

# If you prefer ldapi (local socket), keep this:
php ${BASE_DIR}/ldap_id_pass_from_postgres_set.php --ldap --ldapi

# TARGET groups
TARGET_GROUPS=(
  "users"
  "esmile-dev"
  "nicori-dev"
  "kindaka-dev"
  "boj-dev"
  "e_game-dev"
  "solt-dev"
  "social-dev"
)

# Add --ldapi (or switch to --ldaps or --ldaps=host:port as needed)
for g in "${TARGET_GROUPS[@]}"; do
  php ${BASE_DIR}/ldap_memberuid_users_group.php --ldapi --confirm --init --list --group="$g"
done

# Also add URI switch to the others
php ${BASE_DIR}/ldap_groupmap_smb_add.php   --ldapi --confirm --force
php ${BASE_DIR}/ldap_prune_home_dirs.php    --ldapi

exit
