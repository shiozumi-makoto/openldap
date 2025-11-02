
Patched to use ldap_cli_uri_switch.inc.php for CLI LDAP URI handling.

Changes:
1) Added `require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';` after declare(strict_types=1);
2) Standardized `$LDAP_URL` resolution to prefer environment variables set by the switcher:
   LDAPURI -> LDAP_URI -> LDAP_URL -> --uri option.
3) Kept legacy `--ldapi` fallback only when no URI is provided.

Deploy:
  cp /mnt/data/ldap_id_pass_from_postgres_set_patched.php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php
  cp /mnt/data/ldap_memberuid_users_group_patched.php    /usr/local/etc/openldap/tools/ldap_memberuid_users_group.php

CLI examples (now unified):
  php .../ldap_id_pass_from_postgres_set.php --confirm --ldap --ldapi
  php .../ldap_memberuid_users_group.php --list --ldaps=ovs-012.e-smile.local
  php .../ldap_memberuid_users_group.php --uri=ldaps://ovs-012.e-smile.local
