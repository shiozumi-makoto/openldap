ldapmodify -Y EXTERNAL -H ldapi:/// -f delete-syncrepl.ldif
ldapmodify -Y EXTERNAL -H ldapi:/// -f delete-multiprovider.ldif
ldapmodify -Y EXTERNAL -H ldapi:/// -f delete-syncprov-overlay.ldif

ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "olcDatabase={1}mdb,cn=config"


