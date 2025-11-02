
fix_OVS-024_sid.ldif ‚Æ‚¢‚¤–¼‘O‚Å•Û‘¶F

dn: sambaDomainName=OVS-024,dc=e-smile,dc=ne,dc=jp
changetype: modify
replace: sambaSID
sambaSID: S-1-5-21-3566765955-3362818161-2431109675


ldapmodify -x -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -W -f fix_OVS-024_sid.ldif

ldapsearch -x -LLL -H ldap://127.0.0.1 -b "dc=e-smile,dc=ne,dc=jp" "(sambaDomainName=OVS-024)" sambaDomainName sambaSID

ldapsearch -x -LLL -H ldap://127.0.0.1 -b "dc=e-smile,dc=ne,dc=jp" "(objectClass=sambaDomain)" sambaDomainName sambaSID

[root@ovs-024 shiozumi]# ldapsearch -x -LLL -H ldap://127.0.0.1 -b "dc=e-smile,dc=ne,dc=jp" "(sambaDomainName=OVS-024)" sambaDomainName sambaSID
dn: sambaDomainName=OVS-024,dc=e-smile,dc=ne,dc=jp
sambaDomainName: OVS-024
sambaSID: S-1-5-21-3566765955-3362818161-2431109675


ldapsearch -x -LLL -H ldap://127.0.0.1 -b "dc=e-smile,dc=ne,dc=jp" "(objectClass=sambaDomain)" sambaDomainName sambaSID sambaNextRid

dn: sambaDomainName=E-SMILE,dc=e-smile,dc=ne,dc=jp
sambaDomainName: E-SMILE
sambaSID: S-1-5-21-3566765955-3362818161-2431109675

dn: sambaDomainName=OVS-012,dc=e-smile,dc=ne,dc=jp
sambaDomainName: OVS-012
sambaSID: S-1-5-21-3566765955-3362818161-2431109675
sambaNextRid: 1025

dn: sambaDomainName=OVS-024,dc=e-smile,dc=ne,dc=jp
sambaDomainName: OVS-024
sambaSID: S-1-5-21-3267237301-848188135-2990417903




echo "# openldap-tools" >> README.md
git init
git add README.md
git commit -m "first commit"
git branch -M main
git remote add origin https://github.com/shiozumi-makoto/openldap-tools.git
git push -u origin main

git remote set-url origin git@github.com:shiozumi-makoto/https://github.com/shiozumi-makoto/openldap-tools.git


php ldap_level_groups_sync.php --init-group --ldapi --description --group=e_game-dev,dir-cls
php ldap_level_groups_sync.php --init-group --ldapi --description --group=e_game-dev,dir-cls --confirm
php ldap_level_groups_sync.php --init-group --ldapi --description --group=users,esmile-dev,nicori-dev,kindaka-dev,boj-dev,e_game-dev,solt-dev,social-dev,adm-cls,dir-cls,mgr-cls,mgs-cls,stf-cls,ent-cls,tmp-cls,err-cls --confirm


,adm-cls,dir-cls,mgr-cls,mgs-cls,stf-cls,ent-cls,tmp-cls,err-cls \
  --init-group "${CONFIRM_FLAG[@]}" --ldapi \
  || true



ldapsearch -LLL -H ldaps://ovs-012.e-smile.local:636 -x \
  -D "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" \
  -w 'Makoto87426598' \
  -b "ou=Users,dc=e-smile,dc=ne,dc=jp" \
  "(uid=shiozumi-makoto)"



ldapsearch -LLL -Y EXTERNAL -H ldapi:/// \
  -b "ou=Users,dc=e-smile,dc=ne,dc=jp" dn | \
grep '^dn:' | \
grep -v 'ou=Users,' | \
sed 's/^dn: //' | \
ldapdelete -Y EXTERNAL -H ldapi:///



cat >/tmp/ou_groups.ldif <<'LDIF'
dn: ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: organizationalUnit
ou: Groups
LDIF

ldapadd -Y EXTERNAL -H ldapi:/// -f /tmp/ou_groups.ldif


cat >/tmp/ou_people.ldif <<'LDIF'
dn: ou=Pepple,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: organizationalUnit
ou: Groups
LDIF

ldapadd -Y EXTERNAL -H ldapi:/// -f /tmp/ou_people.ldif


php ldap_id_pass_from_postgres_set.php --ldap --ldapi --confirm


ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Groups,dc=e-smile,dc=ne,dc=jp"
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Users,dc=e-smile,dc=ne,dc=jp" "gidNumber=2003"
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=People,dc=e-smile,dc=ne,dc=jp" "uid=shiozumi-makoto"
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Users,dc=e-smile,dc=ne,dc=jp" "uid=shiozumi-makoto"
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Users,dc=e-smile,dc=ne,dc=jp" dn

ldapdelete -Y EXTERNAL -H ldapi:/// -r "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp"
ldapdelete -Y EXTERNAL -H ldapi:/// -r "ou=Users,dc=e-smile,dc=ne,dc=jp"
ldapdelete -Y EXTERNAL -H ldapi:/// -r "ou=People,dc=e-smile,dc=ne,dc=jp"

ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" dn \
| awk '/^dn: /{print $2}' \
| grep -v '^ou=Groups' \
| while read -r dn; do
    echo "[DEL] $dn"
    ldapmodify -Y EXTERNAL -H ldapi:/// <<EOF
dn: $dn
changetype: modify
delete: memberUid

EOF
done


ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" dn memberUid

ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "ou=Groups,dc=e-smile,dc=ne,dc=jp"
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: organizationalUnit
ou: Groups

dn: cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: users
gidNumber: 100
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1008
sambaGroupType: 2
displayName: users
description: Domain Unix group

dn: cn=adm-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: adm-cls
gidNumber: 3001
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1009
sambaGroupType: 2
description: Domain Unix group
displayName:: QWRtaW5pc3RyYXRvciBDbGFzcyAoMeKAkzIpIC8g566h55CG6ICF6ZqO5bGk

dn: cn=boj-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: boj-dev
gidNumber: 2005
sambaGroupType: 2
displayName: boj-dev
description: Domain Unix group
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1005

dn: cn=dir-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: dir-cls
gidNumber: 3003
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1010
sambaGroupType: 2
description: Domain Unix group
displayName:: RGlyZWN0b3IgQ2xhc3MgKDPigJM0KSAvIOWPlue3oOW9uemajuWxpA==

dn: cn=ent-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: ent-cls
gidNumber: 3020
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1014
sambaGroupType: 2
description: Domain Unix group
displayName:: RW50cnkgQ2xhc3MgKDIwKSAvIOaWsOWFpeekvuWToQ==

dn: cn=err-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: err-cls
gidNumber: 3099
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1016
sambaGroupType: 2
description: Domain Unix group
displayName:: RXJyb3IgQ2xhc3MgKDk5KSAvIOS+i+WkluWHpueQhuODu+acquWumue+qUlE55So

dn: cn=mgr-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: mgr-cls
gidNumber: 3005
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1011
sambaGroupType: 2
description: Domain Unix group
displayName:: TWFuYWdlciBDbGFzcyAoNSkgLyDpg6jploDplbc=

dn: cn=mgs-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: mgs-cls
gidNumber: 3006
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1012
sambaGroupType: 2
description: Domain Unix group
displayName:: U3ViLU1hbmFnZXIgQ2xhc3MgKDbigJMxNCkgLyDoqrLplbfjg7vnm6PnnaPogbc=

dn: cn=stf-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: stf-cls
gidNumber: 3015
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1013
sambaGroupType: 2
displayName:: U3RhZmYgQ2xhc3MgKDE14oCTMTkpIC8g5Li75Lu744O75LiA6Iis56S+5ZOh

dn: cn=tmp-cls,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: tmp-cls
gidNumber: 3021
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1015
sambaGroupType: 2
description: Domain Unix group
displayName:: VGVtcG9yYXJ5IENsYXNzICgyMeKAkzk4KSAvIOa0vumBo+ODu+mAgOiBt+iAhQ==

dn: cn=solt-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: solt-dev
gidNumber: 2010
sambaGroupType: 2
displayName: solt-dev
description: Domain Unix group
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1010

dn: cn=e_game-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: e_game-dev
gidNumber: 2009
sambaGroupType: 2
displayName: e_game-dev
description: Domain Unix group
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1009

dn: cn=esmile-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: esmile-dev
gidNumber: 2001
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1001
sambaGroupType: 2
displayName: esmile-dev
description: Domain Unix group

dn: cn=nicori-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: nicori-dev
gidNumber: 2002
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1002
sambaGroupType: 2
displayName: nicori-dev
description: Domain Unix group

dn: cn=social-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: social-dev
gidNumber: 2012
sambaGroupType: 2
displayName: social-dev
description: Domain Unix group
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1012

dn: cn=kindaka-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
objectClass: sambaGroupMapping
cn: kindaka-dev
gidNumber: 2003
sambaSID: S-1-5-21-3566765955-3362818161-2431109675-1003
sambaGroupType: 2
displayName: kindaka-dev
description: Domain Unix group
