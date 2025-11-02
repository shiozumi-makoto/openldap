#!/bin/bash
#----------------------------------------------------------------------
# olcAccess_show.sh
#  指定ホストの olcAccess を取得し、見やすく整形表示する
#
#  使用例:
#    ./olcAccess_show.sh --host=ovs-012
#    ./olcAccess_show.sh --host=ovs-002 --out=all
#
#  オプション:
#    --host=HOST     対象ホスト名（必須）
#    --out=all       整形せず raw 出力
#    （引数なし）     デフォルト：'by' 区切りで整形
#----------------------------------------------------------------------
set -Eeuo pipefail
LANG=C
LC_ALL=C

HOST=""
OUT_MODE="pretty"   # default

for arg in "$@"; do
  case "$arg" in
    --host=*) HOST="${arg#*=}" ;;
    --out=*) OUT_MODE="${arg#*=}" ;;
    -h|--help)
      echo "Usage: $0 --host=ovs-012 [--out=all]"
      exit 0 ;;
    *) echo "[WARN] unknown arg: $arg" ;;
  esac
done

if [[ -z "$HOST" ]]; then
  echo "[ERROR] --host=HOST is required"
  exit 1
fi

LDAPURI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
SUFFIX='dc=e-smile,dc=ne,dc=jp'

echo "[INFO] Fetching olcAccess from $HOST ..."

# DNを取得
DN=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$HOST" bash -s -- "$LDAPURI" "$SUFFIX" <<'EOS'
LDAPURI="$1"; SUFFIX="$2"
ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
  "(&(objectClass=olcMdbConfig)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
| awk '/^dn: /{print substr($0,5)}' | head -n1
EOS
)

if [[ -z "$DN" ]]; then
  echo "[ERROR] could not determine mdb DN on $HOST"
  exit 1
fi

echo "[INFO] DN: $DN"

# 実データ取得
RAW=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$HOST" \
  "ldapsearch -LLL -Y EXTERNAL -H '$LDAPURI' -b '$DN' olcAccess -o ldif-wrap=no" 2>/dev/null)

# 出力形式分岐
if [[ "$OUT_MODE" == "all" ]]; then
  echo "$RAW"
  exit 0
fi

# 整形処理
echo "$RAW" | awk '
  /^olcAccess:/ {
    # 見出し
    sub(/^olcAccess:[[:space:]]*/, "", $0)
    # by 区切りで改行＋インデント
    gsub(/[[:space:]]+by[[:space:]]+/, "\n by ")
    print "olcAccess: " $0 "\n"
  }'

