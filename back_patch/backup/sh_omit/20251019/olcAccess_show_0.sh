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
#    （引数なし）     デフォルト：'by' 区切りで整形 + コメント付き
#----------------------------------------------------------------------
set -Eeuo pipefail
LANG=C
LC_ALL=C

HOST=""
OUT_MODE="pretty"

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

RAW=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$HOST" \
  "ldapsearch -LLL -Y EXTERNAL -H '$LDAPURI' -b '$DN' olcAccess -o ldif-wrap=no" 2>/dev/null)

if [[ "$OUT_MODE" == "all" ]]; then
  echo "$RAW"
  exit 0
fi

# ===== 整形＋コメント付き =====
echo "$RAW" | awk '
  function comment(line) {
    if (line ~ /gidNumber=0\+uidNumber=0,cn=peercred/) return "# EXTERNAL(root)接続でフルアクセス";
    if (line ~ /cn=Admin,dc=e-smile,dc=ne,dc=jp/) return "# 管理者アカウントで書き込み可";
    if (line ~ /by[[:space:]]+self/) return "# 自分のエントリに対して書き込み可";
    if (line ~ /by[[:space:]]+anonymous[[:space:]]+auth/) return "# 匿名アクセスで認証のみ許可";
    if (line ~ /by[[:space:]]+\*[[:space:]]+auth/) return "# その他は認証のみ許可";
    if (line ~ /by[[:space:]]+\*[[:space:]]+read/) return "# その他のユーザーは読み取り可";
    if (line ~ /by[[:space:]]+\*/) return "# その他のユーザー（デフォルト動作）";
    return "";
  }

  /^olcAccess:/ {
    sub(/^olcAccess:[[:space:]]*/, "", $0);
    split($0, parts, /[[:space:]]+by[[:space:]]+/);
    n = length(parts);
    printf("olcAccess: %s\n", parts[1]);
    for (i = 2; i <= n; i++) {
      line = " by " parts[i];
      c = comment(line);
      if (c != "") printf("%s  %s\n", line, c);
      else print line;
    }
    print "";
    next;
  }

  { print; }
'

