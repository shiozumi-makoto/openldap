#!/bin/bash
#---------------------------------------------------------------
# check_olcAccess_all.sh
#   各ホストの olcAccess 設定を比較取得
#---------------------------------------------------------------

set -Eeuo pipefail

HOSTS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")
OUTDIR="/root/ldap-check"
mkdir -p "$OUTDIR"

echo "[INFO] 開始: $(date '+%F %T')"
echo "[INFO] 出力ディレクトリ: $OUTDIR"
echo

for h in "${HOSTS[@]}"; do
  echo "== $h =="
  ssh "$h" '
    LDAPURI="ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi"
    OUT=$(mktemp)
    ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" \
      -b "olcDatabase={1}mdb,cn=config" olcAccess 2>/dev/null >"$OUT"
    echo "[INFO] $HOSTNAME: $(grep -c "^olcAccess:" "$OUT") entries"
    cat "$OUT"
    rm -f "$OUT"
  ' | tee "$OUTDIR/${h}_olcAccess.ldif"
  echo
done

echo "[INFO] 完了: $(date '+%F %T')"
echo "[INFO] 取得結果: $OUTDIR/*.ldif"


