#!/usr/bin/env bash
set -Eeuo pipefail

php ldap_id_pass_from_postgres_set.php --ldap --ldapi

# php ldap_memberuid_users_group.php --confirm --init --list

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

for g in "${TARGET_GROUPS[@]}"; do
  php ldap_memberuid_users_group.php --confirm --init --list --group="$g"
done

php ldap_groupmap_smb_add.php --confirm --force

php ldap_prune_home_dirs.php
exit
