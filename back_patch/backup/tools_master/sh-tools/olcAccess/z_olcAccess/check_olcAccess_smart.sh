#!/bin/bash
#---------------------------------------------------------------
# check_olcAccess_smart.sh
#  各ホストで "olcSuffix=dc=e-smile,dc=ne,dc=jp" の mdb を自動特定して
#  olcAccess を取得。{1}/{2} などの番号差を吸収します。
#---------------------------------------------------------------
set -Eeuo pipefail

HOSTS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")
OUTDIR="/root/ldap-check"
SUFFIX="dc=e-smile,dc=ne,dc=jp"
LDAPURI_ENC="ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi"  # 環境に合わせて

mkdir -p "$OUTDIR"
echo "[INFO] 開始: $(date '+%F %T')"
echo "[INFO] 出力: $OUTDIR"
echo

for h in "${HOSTS[@]}"; do
  echo "== $h =="

ssh "$h" bash -s <<'EOS' | tee "$OUTDIR/${h}_olcAccess.ldif"
set -Eeuo pipefail
LDAPURI_ENC="ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi"
SUFFIX="dc=e-smile,dc=ne,dc=jp"

# まず olcMdbConfig を優先
DN=$(ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI_ENC" -b "cn=config" \
      "(&(objectClass=olcMdbConfig)(olcSuffix=$SUFFIX))" dn 2>/dev/null \
    | awk '/^dn: /{print substr($0,5)}' | head -n1)

# 見つからない場合は olcSuffix のみでフォールバック
if [[ -z "$DN" ]]; then
  DN=$(ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI_ENC" -b "cn=config" \
        "(olcSuffix=$SUFFIX)" dn 2>/dev/null \
      | awk '/^dn: /{print substr($0,5)}' | head -n1)
fi

if [[ -z "$DN" ]]; then
  echo "[ERROR] mdb (suffix=$SUFFIX) の DN を特定できませんでした"
  # デバッグ用に候補を一覧
  ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI_ENC" -b "cn=config" \
    "(objectClass=olcMdbConfig)" dn olcSuffix 2>/dev/null || true
  exit 1
fi

echo "[INFO] TARGET DN: $DN"
ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI_ENC" -b "$DN" olcAccess olcSuffix
EOS


  echo
done

# ざっくり差分まとめ（任意）
if command -v diff >/dev/null 2>&1; then
  echo "[INFO] 差分チェック（ovs-002 を基準）"
  for h in "${HOSTS[@]:1}"; do
    echo "--- diff: ovs-002 vs $h ---"
    diff -u "$OUTDIR/ovs-002_olcAccess.ldif" "$OUTDIR/${h}_olcAccess.ldif" || true
    echo
  done
fi

echo "[INFO] 完了: $(date '+%F %T')"

