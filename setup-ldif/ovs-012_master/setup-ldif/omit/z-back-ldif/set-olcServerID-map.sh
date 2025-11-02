#!/usr/bin/env bash
set -euo pipefail

WORKDIR="/usr/local/etc/openldap/tmp"
cd "$WORKDIR"

echo "== Step 1: Backup cn=config (slapcat -n0) =="
BACKUP="cnconfig-backup-$(date +%F_%H%M%S).ldif"
slapcat -n0 > "$BACKUP"
echo "  -> $WORKDIR/$BACKUP"

echo "== Step 2: Prepare LDIF =="
cat > olcServerID-map.ldif <<'LDIF'
dn: cn=config
changetype: modify
replace: olcServerID
olcServerID: 24 ldap://ovs-024.e-smile.local
olcServerID: 25 ldap://ovs-025.e-smile.local
olcServerID: 26 ldap://ovs-026.e-smile.local
olcServerID: 12 ldap://ovs-012.e-smile.local
olcServerID: 2  ldap://ovs-002.e-smile.local
LDIF
echo "  -> $WORKDIR/olcServerID-map.ldif"

echo "== Step 3: Apply LDIF to cn=config via ldapi:/// (SASL/EXTERNAL) =="
ldapmodify -Y EXTERNAL -H ldapi:/// -f olcServerID-map.ldif

echo "== Step 4: Read back olcServerID =="
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config olcServerID || true

echo "== Step 5: Restart slapd (serverID is picked at startup) =="
RESTARTED=0
# 5-1) systemd サービス名が slapd なら
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files | grep -q '^slapd\.service'; then
  systemctl restart slapd && RESTARTED=1
fi
# 5-2) もしくは service コマンド
if [ "$RESTARTED" -eq 0 ] && command -v service >/dev/null 2>&1 && service slapd status >/dev/null 2>&1; then
  service slapd restart && RESTARTED=1
fi
# 5-3) 最後の手段: PID/args を用いて手動再起動（/usr/local ビルド想定）
if [ "$RESTARTED" -eq 0 ]; then
  PIDFILE="/usr/local/var/run/slapd.pid"
  ARGSFILE="/usr/local/var/run/slapd.args"
  if [ -f "$PIDFILE" ]; then
    echo "  -> stopping slapd (pid=$(cat "$PIDFILE"))"
    kill "$(cat "$PIDFILE")" || true
    sleep 1
  fi
  if [ -f "$ARGSFILE" ]; then
    # 例: /usr/local/libexec/slapd -h "ldap://... ldapi:/// ldaps:///" -u ldap -g ldap
    SLAPD_CMD=$(head -n1 "$ARGSFILE")
    # pidファイル等は slapd 側で再生成されます
    echo "  -> starting: $SLAPD_CMD"
    nohup $SLAPD_CMD >/dev/null 2>&1 &
    sleep 1
    RESTARTED=1
  else
    echo "ERROR: $ARGSFILE が見つかりません。slapd の起動方法が不明です。" >&2
    exit 1
  fi
fi

echo "== Step 6: Verify slapd is up =="
sleep 1
pgrep -x slapd >/dev/null && echo "  -> slapd running (pid: $(pgrep -x slapd | tr '\n' ' '))" || { echo "ERROR: slapd not running"; exit 1; }

echo "== Step 7: Quick sanity check (this node's picked ServerID will match its URL) =="
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config olcServerID

echo "[OK] Completed on $(hostname -f)"

