
export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'

export BIND_PW='********'

php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap





[root@ovs-012 tools]# php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
=== START add-home(+LDAP) ===
HOST      : ovs-012.e-smile.local
HOME_ROOT : /home
SKEL      : /etc/skel
MODE      : 750 (488)
CONFIRM   : YES (execute)
LDAP      : disabled
-----------
[INFO] /etc/passwd local keep: shiozumi,www
[KEEP][EXIST] HOME: /home/09-605-nakamura-suzuka
[KEEP][EXIST] HOME: /home/10-277-iwata-yuushirou





export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'
php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap


export PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp'


[root@ovs-012 tools]# export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'
export PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp'
php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
=== START add-home(+LDAP) ===
HOST      : ovs-012.e-smile.local
HOME_ROOT : /home
SKEL      : /etc/skel
MODE      : 0750 (488)
CONFIRM   : YES (execute)
LDAP      : enabled
-----------
[INFO] /etc/passwd local keep: shiozumi,www
[KEEP][EXIST] HOME: /home/01-001-shiozumi-makoto
[LDAP][SKIP] uid/gid missing cmp_id=1 user_id=1 uid=shiozumi-makoto
[KEEP][EXIST] HOME: /home/02-001-shiozumi-makoto2
[LDAP][SKIP] uid/gid missing cmp_id=2 user_id=1 uid=shiozumi-makoto2
[KEEP][EXIST] HOME: /home/03-001-shiozumi-makoto3
[LDAP][SKIP] uid/gid missing cmp_id=3 user_id=1 uid=shiozumi-makoto3
[KEEP][EXIST] HOME: /home/03-156-kurabayashi-akira
[LDAP][SKIP] uid/gid missing cmp_id=3 user_id=156 uid=kurabayashi-akira
[KEEP][EXIST] HOME: /home/03-207-watanabe-toshihiro
[LDAP][SKIP] uid/gid missing cmp_id=3 user_id=207 uid=watanabe-toshihiro


ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi*)" uidNumber gidNumber homeDirectory dn

dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 10001
gidNumber: 2001
homeDirectory: /home/01-001-shiozumi-makoto

dn: uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 20001
gidNumber: 2002
homeDirectory: /home/02-001-shiozumi-makoto2

dn: uid=shiozumi-makoto3,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 30001
gidNumber: 2003
homeDirectory: /home/03-001-shiozumi-makoto3

dn: uid=shiozumi-2-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 20001
gidNumber: 2002
homeDirectory: /home/02-001-shiozumi-2-makoto

dn: uid=shiozumi-3-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 30001
gidNumber: 2003
homeDirectory: /home/03-001-shiozumi-3-makoto

dn: uid=yamamoto-tatsuyoshi,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 100274
gidNumber: 2010
homeDirectory: /home/10-274-yamamoto-tatsuyoshi

dn: uid=yamanishi-toshihiro,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 100149
gidNumber: 2010
homeDirectory: /home/10-149-yamanishi-toshihiro

dn: uid=yanagisawa-masamichi,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 100118
gidNumber: 2010
homeDirectory: /home/10-118-yanagisawa-masamichi

uidNumber: 100149 ‚ÍA cmp_id * 10000 + user_id
gidNumber: 2010	‚ÍA2000 + cmp_id







[root@ovs-012 tools]# ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi*)" uidNumber gidNumber dn
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 10001
gidNumber: 2001

dn: uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 20001
gidNumber: 2002

dn: uid=shiozumi-makoto3,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 30001
gidNumber: 2003

dn: uid=shiozumi-2-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 20001
gidNumber: 2002

dn: uid=shiozumi-3-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uidNumber: 30001
gidNumber: 2003


ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)"
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uid: shiozumi-makoto
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: sambaSamAccount
cn:: 5aGp5L2P6Kqg
sn:: 5aGp5L2P
displayName:: 5aGp5L2P6Kqg
uidNumber: 10001
gidNumber: 2001
homeDirectory: /home/01-001-shiozumi-makoto



[root@ovs-012 tools]# ldapsearch -LLL -x   -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW"   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=inagaki-wanoka)" objectClass
dn: uid=inagaki-wanoka,ou=Users,dc=e-smile,dc=ne,dc=jp
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: sambaSamAccount


[root@ovs-012 tools]# ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)"
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uid: shiozumi-makoto
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: sambaSamAccount
cn:: 5aGp5L2P6Kqg
sn:: 5aGp5L2P
displayName:: 5aGp5L2P6Kqg
uidNumber: 10001
gidNumber: 2001
homeDirectory: /home/01-001-shiozumi-makoto
loginShell: /bin/bash
userPassword:: e1NTSEF9cTdLdkhNWU84SnhMRWNXdlN2d3RMWFJsRVZBK3c3cSs=
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-10001
sambaNTPassword: D5EC50C26E64961D9A26E6E602A906A3
sambaAcctFlags: [U          ]
sambaPwdLastSet: 1760136973
sambaPrimaryGroupSID: S-1-5-21-3566765955-3362818161-2431109675-2001
mail: shiozumi@e-smile.ne.jp



export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'

ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'Makoto87426598'
ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'Makoto87426598'

ldappasswd -H ldaps://ovs-012.e-smile.local \
  -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566' \
  -S "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp"




ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$BASE_GROUPS" "(memberUid=shiozumi-makoto2)" dn \
| awk -v u="shiozumi-makoto2" '/^dn: /{print "dn: "substr($0,5) "\nchangetype: modify\ndelete: memberUid\nmemberUid: " u "\n-"}' \
> /tmp/del-memberuid-shiozumi-makoto2.ldif

[ -s /tmp/del-memberuid-shiozumi-makoto2.ldif ] && ldapmodify -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/del-memberuid-shiozumi-makoto2.ldif || echo "memberUid ŠY“–‚È‚µ"

ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$BASE_GROUPS" "(memberUid=shiozumi-makoto2)" dn || true


export UID_X='shiozumi-makoto2'


DN=$(ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
      -b "$BASE_USERS" "(uid=$UID_X)" dn | awk '/^dn: /{print substr($0,5)}')

echo "DELETE DN: $DN"
[ -n "$DN" ] && ldapdelete -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" "$DN"


HOMEDIR=$(printf "/home/02-001-%s" "$UID_X")
if [ -d "$HOMEDIR" ]; then
  TS=$(date +%Y%m%d-%H%M%S)
  mv -v "$HOMEDIR" "${HOMEDIR}.bak.${TS}"
fi


DRY_RUN=1 php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --ldap



[root@ovs-012 tools]# DRY_RUN=1 php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --ldap
=== START add-home(+LDAP) ===
HOST      : ovs-012.e-smile.local
HOME_ROOT : /home
SKEL      : /etc/skel
MODE      : 0750 (488)
CONFIRM   : NO (dry-run)
LDAP      : enabled
-----------
[INFO] /etc/passwd local keep: shiozumi,www
[KEEP][EXIST] HOME: /home/01-001-shiozumi-makoto
[CREATE][DRY] HOME: /home/02-001-shiozumi-makoto2
[KEEP][EXIST] HOME: /home/03-001-shiozumi-makoto3


[KEEP][EXIST] HOME: /home/12-208-nakabayashi-yuugo
[KEEP][EXIST] HOME: /home/12-209-inagaki-wanoka
[LDAP][DRY][MOD] uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
[LDAP][DRY][ADD] uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp
[LDAP][DRY][MOD] uid=shiozumi-makoto3,ou=Users,dc=e-smile,dc=ne,dc=jp




[root@ovs-012 tools]# php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
=== START add-home(+LDAP) ===
HOST      : ovs-012.e-smile.local
HOME_ROOT : /home
SKEL      : /etc/skel
MODE      : 0750 (488)
CONFIRM   : YES (execute)
LDAP      : enabled
-----------
[INFO] /etc/passwd local keep: shiozumi,www
[KEEP][EXIST] HOME: /home/01-001-shiozumi-makoto
[ADD ][HOME] /home/02-001-shiozumi-makoto2 (mode=750)


[KEEP][EXIST] HOME: /home/12-209-inagaki-wanoka
[LDAP][OK  ][MOD] uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
[LDAP][OK  ][ADD] uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp
[LDAP][OK  ][MOD] uid=shiozumi-makoto3,ou=Users,dc=e-smile,dc=ne,dc=jp


ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'WDXzk7RP'


[root@ovs-012 tools]# ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'Makoto87426598'
ldap_bind: Invalid credentials (49)

ldappasswd -H ldaps://ovs-012.e-smile.local \
  -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566' \
  -S "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp"






php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set_new.php  --confirm --ldap






ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$BASE_GROUPS" "(memberUid=shiozumi-makoto2)" dn \
| awk -v u="shiozumi-makoto2" '/^dn: /{print "dn: "substr($0,5) "\nchangetype: modify\ndelete: memberUid\nmemberUid: " u "\n-"}' \
> /tmp/del-memberuid-shiozumi-makoto2.ldif

[ -s /tmp/del-memberuid-shiozumi-makoto2.ldif ] && ldapmodify -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/del-memberuid-shiozumi-makoto2.ldif || echo "memberUid ŠY“–‚È‚µ"

ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$BASE_GROUPS" "(memberUid=shiozumi-makoto2)" dn || true

export UID_X='shiozumi-makoto2'

DN=$(ldapsearch -LLL -x -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
      -b "$BASE_USERS" "(uid=$UID_X)" dn | awk '/^dn: /{print substr($0,5)}')

echo "DELETE DN: $DN"
[ -n "$DN" ] && ldapdelete -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" "$DN"


ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)"

ldapsearch -LLL -x   -H ldaps://ovs-012.e-smile.local   -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto*)" dn
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp

dn: uid=shiozumi-makoto3,ou=Users,dc=e-smile,dc=ne,dc=jp

HOMEDIR=$(printf "/home/02-001-%s" "$UID_X")
if [ -d "$HOMEDIR" ]; then
  TS=$(date +%Y%m%d-%H%M%S)
  mv -v "$HOMEDIR" "${HOMEDIR}.bak.${TS}"
fi


php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set_new.php  --confirm --ldap

ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'WDXzk7RP'

php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php

ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'WDXzk7RP'


export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'

php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set_master.php  --confirm --ldap


export LDAP_URL='ldaps://ovs-012.e-smile.local'
export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
export BIND_PW='es0356525566'
export BASE_DN='dc=e-smile,dc=ne,dc=jp'

php /usr/local/etc/openldap/tools/ldap_memberuid_users_group.php --confirm

unset LDAP_URL
unset BIND_DN
unset BIND_PW
