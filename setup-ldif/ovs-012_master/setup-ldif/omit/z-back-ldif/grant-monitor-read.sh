#!/usr/bin/env bash
set -euo pipefail
cd /usr/local/etc/openldap/tmp

echo "== Detect monitor database DN in cn=config =="
# 代表的なクエリを順番に試して DN を取得
DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config \
      '(objectClass=olcMonitorConfig)' dn 2>/dev/null | awk '/^dn: /{print substr($0,5)}' | head -n1 || true)
if [[ -z "${DN:-}" ]]; then
  DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config \
        '(olcDatabase=*monitor*)' dn 2>/dev/null | awk '/^dn: /{print substr($0,5)}' | head -n1 || true)
fi
if [[ -z "${DN:-}" ]]; then
  DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config \
        '(olcDatabase=monitor)' dn 2>/dev/null | awk '/^dn: /{print substr($0,5)}' | head -n1 || true)
fi
if [[ -z "${DN:-}" ]]; then
  for cand in "olcDatabase={-1}monitor,cn=config" "olcDatabase={0}monitor,cn=config" "olcDatabase={1}monitor,cn=config" "olcDatabase={2}monitor,cn=config"; do
    if ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$cand" dn >/dev/null 2>&1 ; then
      DN="$cand"; break
    fi
  done
fi
if [[ -z "${DN:-}" ]]; then
  echo "ERROR: monitor DB dn が見つかりません。'slapcat -n0 | grep -n \"monitor\"' で確認してください。" >&2
  exit 1
fi
echo "Monitor DB DN: ${DN}"

echo "== Current olcAccess (before) =="
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DN" olcAccess || true
echo

# 既存の olcAccess が強い deny を先に持っていると、新規 add が後ろに付いて効かない可能性があるため、
# 可能であれば {0} で「先頭に挿入」を試みる。失敗したら通常 add にフォールバック。
cat > add-monitor-acl-first.ldif <<LDIF
dn: ${DN}
changetype: modify
add: olcAccess
olcAccess: {0}to dn.subtree="cn=Monitor"
  by dn.exact="cn=Admin,dc=e-smile,dc=ne,dc=jp" read
  by * none
LDIF

if ldapmodify -Y EXTERNAL -H ldapi:/// -f add-monitor-acl-first.ldif ; then
  echo "[OK] Prepend ACL inserted as {0}"
else
  echo "[i] Prepend failed; try append instead..."
  cat > add-monitor-acl-append.ldif <<LDIF
dn: ${DN}
changetype: modify
add: olcAccess
olcAccess: to dn.subtree="cn=Monitor"
  by dn.exact="cn=Admin,dc=e-smile,dc=ne,dc=jp" read
  by * none
LDIF
  ldapmodify -Y EXTERNAL -H ldapi:/// -f add-monitor-acl-append.ldif
fi

echo "== Read-back olcAccess (after) =="
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DN" olcAccess
echo "[DONE] Monitor read ACL for Admin is in place."
