#!/usr/bin/env bash
# /usr/local/etc/openldap/tools/check_syncprov_all.sh
set -Eeuo pipefail

SSH_USER="${SSH_USER:-root}"
HOSTS=(ovs-012 ovs-002 ovs-024 ovs-025 ovs-026)

echo "=== SyncProv overlay check (via SSH+ldapi EXTERNAL) ==="
for H in "${HOSTS[@]}"; do
  echo
  echo "---- $H ----"
  ssh -o BatchMode=yes -o ConnectTimeout=5 "${SSH_USER}@${H}" \
    'ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" \
      "(olcOverlay=syncprov)" dn olcSpCheckpoint olcSpSessionlog 2>/dev/null || true' \
    | sed "s/^/[${H}] /" || true

  # ついでに syncprov モジュールロード有無も確認
  ssh -o BatchMode=yes -o ConnectTimeout=5 "${SSH_USER}@${H}" \
    'ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=module,cn=config" \
      "(olcModuleLoad=syncprov*)" dn olcModuleLoad 2>/dev/null || true' \
    | sed "s/^/[${H}] /" || true
done
echo
echo "=== Done ==="
