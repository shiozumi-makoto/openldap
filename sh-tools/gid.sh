#!/bin/bash
# ldapi 経由で、posixGroup の Samba 関連属性を一覧
ldapsearch -LLL -Y EXTERNAL \
  -H ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi \
  -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" \
  "(objectClass=posixGroup)" cn gidNumber objectClass displayName sambaSID sambaGroupType \
| awk '
  BEGIN{RS=""; FS="\n"; OFS=" "; printf "%-12s %-6s %-6s %-40s %-5s %s\n","cn","gid","map","sambaSID","type","displayName"}
  {
    cn=""; gid=""; sid=""; typ=""; disp=""; map="NO"
    for(i=1;i<=NF;i++){
      if($i ~ /^cn:/){split($i,a,": "); cn=a[2]}
      else if($i ~ /^gidNumber:/){split($i,a,": "); gid=a[2]}
      else if($i ~ /^displayName:/){sub(/^displayName: /,"",$i); disp=$i}
      else if($i ~ /^sambaSID:/){split($i,a,": "); sid=a[2]}
      else if($i ~ /^sambaGroupType:/){split($i,a,": "); typ=a[2]}
      else if($i ~ /^objectClass:/ && tolower($i) ~ /sambagroupmapping/){map="YES"}
    }
    printf "%-12s %-6s %-6s %-40s %-5s %s\n", cn, gid, map, (sid?sid:"-"), (typ?typ:"-"), (disp?disp:"-")
  }'
