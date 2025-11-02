PHP Fatal error:  Uncaught PDOException: SQLSTATE[42P01]: Undefined table: 7 ERROR:  リレーション"public.passwd_tnas"は存在しません
LINE 8: FROM public.passwd_tnas
             ^ in /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php:277
Stack trace:
#0 /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php(277): PDOStatement->execute()
#1 /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php(140): pg_query_all()
#2 {main}
  thrown in /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php on line 277





ldap_memberuid_users_group.php
ldap_smb_groupmap_sync.php
prune_home_dirs.php

こちらも、全て全文でお願いします。

