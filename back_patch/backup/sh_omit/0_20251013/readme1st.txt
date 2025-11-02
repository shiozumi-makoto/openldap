
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

uidNumber: 100149 は、 cmp_id * 10000 + user_id
gidNumber: 2010	は、2000 + cmp_id







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

[ -s /tmp/del-memberuid-shiozumi-makoto2.ldif ] && ldapmodify -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/del-memberuid-shiozumi-makoto2.ldif || echo "memberUid 該当なし"

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

[ -s /tmp/del-memberuid-shiozumi-makoto2.ldif ] && ldapmodify -H "$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" -f /tmp/del-memberuid-shiozumi-makoto2.ldif || echo "memberUid 該当なし"

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

export LDAP_URI='ldapi:///'
export LDAP_URI='ldapi://%2Fvar%2Frun%2Fldapi'


php /usr/local/etc/openldap/tools/ldap_memberuid_users_group.php --confirm

unset LDAP_URL
unset BIND_DN
unset BIND_PW

export LDAP_URI='ldapi://var/run/ldapi'
export LDAP_URI='ldapi:///'
ldapsearch -LLL -x -H $LDAP_URI -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)"

export LDAP_URI='ldapi://%2Fvar%2Frun%2Fldapi'

/usr/local/var/run/ldapi


ls -la /var/run/ldapi
ldapsearch -LLL -x -H ldapi://%2Fvar%2Frun%2Fldapi/ -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)" dn
ldapsearch -LLL -x -H ldapi:/// -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566'   -b "ou=Users,dc=e-smile,dc=ne,dc=jp"   "(uid=shiozumi-makoto)" dn


[root@ovs-012 tools]# cat /etc/openldap/ldap.conf
# Turning this off breaks GSSAPI used with krb5 when rdns = false
SASL_NOCANON    on

TLS_CACERT /usr/local/etc/openldap/certs/cacert.crt

BASE    dc=e-smile,dc=ne,dc=jp

TLS_REQCERT demand

# URI ldapi://%2Fvar%2Frun%2Fldapi ldaps://ovs-012.e-smile.local

URI ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi ldaps://ovs-012.e-smile.local




# LDAPI（ローカル・SASL EXTERNAL）
ldapsearch -LLL -Q -Y EXTERNAL -H ldapi:/// -s base -b '' vendorName supportedSASLMechanisms

# LDAP(389)（匿名 or 簡単認証）
ldapsearch -LLL -x -H ldap://192.168.61.12 -s base -ZZ -b '' namingContexts

ldapsearch -LLL -x -H ldap://ovs-012.e-smile.local -ZZ -b '' -s base namingContexts
dn:
namingContexts: dc=e-smile,dc=ne,dc=jp


# LDAPS(636)（証明書検証）
openssl s_client -connect 192.168.61.12:636 -showcerts </dev/null | openssl x509 -noout -subject -issuer
ldapsearch -LLL -x -H ldaps://192.168.61.12 -s base -b '' namingContexts



[root@ovs-012 ~]# strace -e connect -f \
  ldapsearch -LLL -x -H ldapi:/// -s base -b '' namingContexts
connect(3, {sa_family=AF_UNIX, sun_path="/usr/local/var/run/ldapi"}, 110) = 0
dn:
namingContexts: dc=e-smile,dc=ne,dc=jp

+++ exited with 0 +++

export LDAP_URI='ldapi:///'
export LDAPURI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
export LDAP_URI=$LDAPURI

unset LDAPURI LDAP_CONF LDAP_URI



[root@ovs-012 ~]# cat /usr/local/etc/openldap/ldap.conf
URI ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi
BASE dc=e-smile,dc=ne,dc=jp
TLS_CACERT /usr/local/etc/openldap/certs/cacert.crt
TLS_REQCERT demand

ls -la /usr/local/var/run/ldapi

[root@ovs-012 ~]# ls -la /usr/local/var/run/ldapi
srwxrwxrwx 1 root root 0 10月 12 15:05 /usr/local/var/run/ldapi
[root@ovs-012 ~]# ls -la /usr/local/var/run/
合計 8
drwxr-xr-x 2 ldap ldap  54 10月 12 15:05 .
drwxr-xr-x 5 root root  48 10月 11 03:37 ..
srwxrwxrwx 1 root root   0 10月 12 15:05 ldapi
-rw-r--r-- 1 ldap ldap 195 10月 12 15:05 slapd.args
-rw-r--r-- 1 ldap ldap   5 10月 12 15:05 slapd.pid



[root@ovs-012 tools]# ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "olcDatabase={1}mdb,cn=config" olcAccess
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0


dn: olcDatabase={1}mdb,cn=config

olcAccess: {0}to attrs=memberUid by dn.exact="cn=admin,dc=e-smile,dc=ne,dc=jp"
  write by * break

olcAccess: {1}to attrs=userPassword by dn.exact="cn=syncuser,dc=e-smile,dc=ne,
 dc=jp" read by self write by anonymous auth by * none

olcAccess: {2}to * by dn.exact="cn=syncuser,dc=e-smile,dc=ne,dc=jp" read by an
 onymous read by * break

olcAccess: {3}to * by dn.base="cn=admin,dc=e-smile,dc=ne,dc=jp" write by users
  read by anonymous none



printf 'dn: cn=config\nchangetype: modify\nadd: olcAuthzRegexp\nolcAuthzRegexp: uidNumber=0\\+gidNumber=0,cn=peercred,cn=external,cn=auth\n cn=admin,dc=e-smile,dc=ne,dc=jp\n' | ldapmodify -Y EXTERNAL -H ldapi:///



