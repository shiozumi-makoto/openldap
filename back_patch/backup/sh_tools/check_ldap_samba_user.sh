#!/bin/bash

# ===== 設定項目（必要に応じて編集） =====
USER="$1"
LDAP_ADMIN_DN="cn=admin,dc=e-smile,dc=ne,dc=jp"
LDAP_BASE_DN="dc=e-smile,dc=ne,dc=jp"
LDAP_HOST="localhost"
# ========================================

if [ -z "$USER" ]; then
    echo "Usage: $0 <username>"
    exit 1
fi

echo "?? LDAP user diagnostic for: $USER"
echo "========================================"
echo "1?? LDAP - userPassword / sambaNTPassword 確認"
ldapsearch -x -H ldap://$LDAP_HOST -D "$LDAP_ADMIN_DN" -W -b "$LDAP_BASE_DN" "(uid=$USER)" userPassword sambaNTPassword | grep -E '^(dn:|userPassword|sambaNTPassword)'

echo
echo "2?? getent - UNIXアカウントとして認識されているか"
getent passwd "$USER" && echo "? getent OK" || echo "? getent で見つかりません"

echo
echo "3?? pdbedit - Samba に登録されているか"
pdbedit -L -v "$USER" 2>/dev/null | grep -E '^(Unix username|User SID|NT username|Home Directory|Password last set)' && echo "? pdbedit OK" || echo "? pdbedit に見つかりません"

echo
echo "4?? smbclient - Samba ログインテスト"
echo "パスワードを入力してください（$USER 用）:"
smbclient -L //localhost -U "$USER"


