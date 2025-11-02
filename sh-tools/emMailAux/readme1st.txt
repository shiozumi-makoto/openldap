C:\Users\shioz\AppData\Roaming\Thunderbird\Profiles\2ng6dmb5.default-esr

user_pref("ldap_2.autoComplete.directoryServer", "ldap_2.servers.ESmile");
user_pref("ldap_2.autoComplete.useDirectory", true);
user_pref("ldap_2.servers.ESmile.auth.dn", "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp");
user_pref("ldap_2.servers.ESmile.auth.saslmech", "");
user_pref("ldap_2.servers.ESmile.description", "E-Smile 社員名簿");
user_pref("ldap_2.servers.ESmile.filename", "ldap.sqlite");
user_pref("ldap_2.servers.ESmile.maxHits", 100);
user_pref("ldap_2.servers.ESmile.uid", "4034dd84-158c-4149-8933-3c831504fb49");
user_pref("ldap_2.servers.ESmile.uri", "ldaps://ovs-012.e-smile.local/ou=Users,dc=e-smile,dc=ne,dc=jp??sub?(objectclass=*)");
user_pref("ldap_2.servers.history.uid", "cb578104-504f-4c8d-8641-97f01e436d0e");
user_pref("ldap_2.servers.pab.uid", "7009b325-1fb3-493c-80b2-30f9c97c476c");
user_pref("ldap_2.servers.ESmile.attrmap.DisplayName", "displayName");
user_pref("ldap_2.servers.ESmile.autoComplete.nameFormat", "displayName");




ldapwhoami -x -H ldaps://ovs-012.e-smile.local:636   -D "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" -w Makoto87426598


for h in ovs-009 ovs-010 ovs-011; do
  echo "== $h ==";
  ldapsearch -LLL -x -H ldap://$h \
    -D 'cn=Admin,dc=e-smile,dc=ne,dc=jp' -y /root/.ldap-pass \
    -b 'dc=e-smile,dc=ne,dc=jp' -s base -e manageDSAit contextCSN;
done


for h in ovs-024 ovs-025 ovs-026 ovs-012 ovs-002; do
  echo "== $h ==";
  ldapsearch -LLL -x -H ldap://$h \
    -D 'cn=Admin,dc=e-smile,dc=ne,dc=jp' -y /root/.ldap-pass \
    -b 'dc=e-smile,dc=ne,dc=jp' -s base -e manageDSAit contextCSN;
done



HOSTS_OVERRIDE="ovs-009 ovs-010 ovs-011" APPLY_INDEX=1 APPLY_REINDEX=0 ./deploy_emMailAux_schema.sh

ssh root@ovs-009 "ldapmodify -Y EXTERNAL -H ldapi:/// <<'LDIF'
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
changetype: modify
add: objectClass
objectClass: emMailAux
-
add: mailAlternateAddress
mailAlternateAddress: shiozumi.makoto@gmail.com
LDIF"


[root@ovs-002 ~]# ldapsearch -LLL -x -H ldaps://ovs-009.e-smile.local:636   -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp

[root@ovs-002 ~]# ldapsearch -LLL -x -H ldaps://ovs-010.e-smile.local:636   -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp

[root@ovs-002 ~]# ldapsearch -LLL -x -H ldaps://ovs-011.e-smile.local:636   -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp



ldapmodify -Y EXTERNAL -H ldapi:/// <<'LDIF'
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
changetype: modify
add: objectClass
objectClass: emMailAux
-
add: mailAlternateAddress
mailAlternateAddress: shiozumi.makoto@gmail.com
LDIF


ldapmodify -Y EXTERNAL -H ldapi:/// <<'LDIF'
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
changetype: modify
add: mailAlternateAddress
mailAlternateAddress: shiozumi.makoto@gmail.com
LDIF



HOSTS_OVERRIDE="ovs-002" APPLY_INDEX=1 APPLY_REINDEX=0 ./deploy_emMailAux_schema.sh
HOSTS_OVERRIDE="ovs-012" APPLY_INDEX=1 APPLY_REINDEX=0 ./deploy_emMailAux_schema.sh
HOSTS_OVERRIDE="ovs-012" APPLY_INDEX=1 APPLY_REINDEX=0 ./deploy_emMailAux_schema.sh

ldapsearch -LLL -Y EXTERNAL -H ldapi:/// \
  -b cn=schema,cn=config '(cn=emMailAux)' cn olcAttributeTypes olcObjectClasses

ldapsearch -LLL -x -H ldaps://ovs-012.e-smile.local:636 \
  -b "dc=e-smile,dc=ne,dc=jp" \
  "(mailAlternateAddress=shiozumi.makoto@gmail.com)" \
  mailAlternateAddress



cat > ./emMailAux_fix.ldif <<'LDIF'
dn: cn=emMailAux,cn=schema,cn=config
changetype: modify
add: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1
  NAME 'mailAlternateAddress'
  DESC 'Additional email addresses for a person'
  EQUALITY caseIgnoreIA5Match
  SUBSTR caseIgnoreIA5SubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.26
  SINGLE-VALUE FALSE )
-
add: olcObjectClasses
olcObjectClasses: ( 1.3.6.1.4.1.55555.1.2
  NAME 'emMailAux'
  DESC 'Aux class to hold alternate email addresses'
  SUP top
  AUXILIARY
  MAY ( mailAlternateAddress ) )
LDIF

ldapmodify -Y EXTERNAL -H ldapi:/// <<'LDIF'
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
changetype: modify
add: objectClass
objectClass: emMailAux
-
add: mailAlternateAddress
mailAlternateAddress: shiozumi.makoto@gmail.com
LDIF





# 検索ヒット確認（索引を使うクエリ）
ldapsearch -LLL -H ldap://127.0.0.1 \
  -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn

ldapsearch -LLL -Y EXTERNAL -H ldapi:/// \
  -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn


ldapmodify -Y EXTERNAL -H ldapi:/// <<'LDIF'
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
changetype: modify
add: objectClass
objectClass: emMailAux
-
add: mailAlternateAddress
mailAlternateAddress: shiozumi.makoto@gmail.com
LDIF
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
modifying entry "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp"

[root@ovs-002 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:/// \
  -b "dc=e-smile,dc=ne,dc=jp" "(mailAlternateAddress=shiozumi.makoto@gmail.com)" dn
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp















[root@ovs-012 emMailAux]# ldapsearch -LLL -Y EXTERNAL -H ldapi:///   -b "cn=schema,cn=config" "(olcAttributeTypes=*displayOrderInt*)"
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0

dn: cn={6}emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: {6}emMailAux

olcAttributeTypes: {0}( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC
  'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR
  caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )

olcAttributeTypes: {1}( 1.3.6.1.4.1.55555.1.3 NAME 'displayNameOrder' DESC 'So
 rtable display name for address book' EQUALITY caseIgnoreMatch ORDERING caseI
 gnoreOrderingMatch SUBSTR caseIgnoreSubstringsMatch SYNTAX 1.3.6.1.4.1.1466.1
 15.121.1.15 )

olcAttributeTypes: {2}( 1.3.6.1.4.1.55555.1.31 NAME 'displayOrderInt' DESC 'In
 teger order for display sorting' EQUALITY integerMatch ORDERING integerOrderi
 ngMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )

olcObjectClasses: {0}( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class
 to hold alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddre
 ss  $ displayNameOrder  $ displayOrderInt ) )







[root@ovs-025 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base   attributetypes objectclasses | egrep -i 'mailAlternateAddress|displayOrderInt|emMailAux'
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
attributeTypes: ( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Display o
attributeTypes: ( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC 'Addi
objectClasses: ( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class to hol
 d alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddress $ d



[root@ovs-025 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:///   -b "cn=schema,cn=config" "(olcAttributeTypes=*displayOrderInt*)"
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: cn={6}emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: {6}emMailAux

olcAttributeTypes: {0}( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Dis
 play order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrder
 ingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )

olcAttributeTypes: {1}( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC
  'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR
  caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )

olcObjectClasses: {0}( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class
 to hold alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddre
 ss $ displayOrderInt ) )



[root@ovs-024 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:///   -b "cn=schema,cn=config" "(olcAttributeTypes=*displayOrderInt*)"
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: cn={6}emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: {6}emMailAux

olcAttributeTypes: {0}( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC
  'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR
  caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )

olcAttributeTypes: {1}( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Dis
 play order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrder
 ingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )

olcObjectClasses: {0}( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class
 to hold alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddre
 ss $ displayOrderInt ) )


[root@ovs-026 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:///   -b "cn=schema,cn=config" "(olcAttributeTypes=*displayOrderInt*)"
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: cn={6}emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: {6}emMailAux

olcAttributeTypes: {0}( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC
  'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR
  caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )

olcAttributeTypes: {1}( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Dis
 play order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrder
 ingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )

olcObjectClasses: {0}( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class
 to hold alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddre
 ss $ displayOrderInt ) )



[root@ovs-002 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:/// \
  -b "cn={6}emMailAux,cn=schema,cn=config" -s base \
  olcAttributeTypes olcObjectClasses
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
dn: cn={6}emMailAux,cn=schema,cn=config

olcAttributeTypes: {0}( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC
  'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR
  caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )

olcAttributeTypes: {1}( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Dis
 play order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrder
 ingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )
olcAttributeTypes: {2}( 1.3.6.1.4.1.55555.1.2 NAME 'displayNameOrder' DESC 'So

 rt key for display name' EQUALITY caseIgnoreMatch SYNTAX 1.3.6.1.4.1.1466.115
 .121.1.15 )

olcObjectClasses: {0}( 1.3.6.1.4.1.55555.2.1 NAME 'emMailAux' DESC 'Aux class
 to hold alternate emails and display order' SUP top AUXILIARY MAY ( mailAlter
 nateAddress $ displayNameOrder $ displayOrderInt ) )






[root@ovs-026 ~]# ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributetypes objectclasses \
 | egrep -i 'mailAlternateAddress|displayOrderInt|emMailAux'
SASL/EXTERNAL authentication started
SASL username: gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth
SASL SSF: 0
attributeTypes: ( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC 'Addi
attributeTypes: ( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Display o
objectClasses: ( 1.3.6.1.4.1.55555.1.2 NAME 'emMailAux' DESC 'Aux class to hol
 d alternate email addresses' SUP top AUXILIARY MAY ( mailAlternateAddress $ d




for h in ovs-024 ovs-025 ovs-026; do
  echo "== $h =="
  ssh root@"$h" '
    ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributetypes objectclasses \
      | egrep -qi "mailAlternateAddress|displayOrderInt|emMailAux" && echo "  schema OK" || echo "  schema NG"
  '
done





for h in ovs-024 ovs-025 ovs-026 ovs-002; do
  echo "== $h =="
  ssh root@"$h" '
    ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributetypes objectclasses \
      | egrep -qi "mailAlternateAddress|displayOrderInt|emMailAux" && echo "  schema OK" || echo "  schema NG"
  '
done



