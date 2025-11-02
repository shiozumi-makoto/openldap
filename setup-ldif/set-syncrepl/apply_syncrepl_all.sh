#!/bin/bash
# apply_syncrepl_all.sh
# 各サーバーの LDIF を流し込む
# __REPL_PASS__ を自動置換して ldapmodify にパイプする

set -Eeuo pipefail

cd /usr/local/etc/openldap/tools

# ===== 設定 =====
REPL_PASS='es0356525566'
URI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
HOSTS=(ovs-012 ovs-024 ovs-025 ovs-026 ovs-002)

for h in "${HOSTS[@]}"; do
  LDIF="set-syncrepl-${h}.ldif"
  echo "== ${h} =="

  if [[ ! -f "$LDIF" ]]; then
    echo "[WARN] Missing file: $LDIF"
    continue
  fi

  # プレースホルダ置換して適用
  sed "s/__REPL_PASS__/${REPL_PASS}/g" "$LDIF" \
    | ssh "$h" "ldapmodify -Q -Y EXTERNAL -H '$URI'"

  echo "[$h] done."
  echo
done

echo "[ALL DONE] 全サーバーへの olcSyncrepl 設定を反映しました。"

