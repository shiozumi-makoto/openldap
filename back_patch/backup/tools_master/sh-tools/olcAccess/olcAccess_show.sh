#!/bin/bash
#----------------------------------------------------------------------
# olcAccess_show.sh
#  指定ホストの olcAccess を取得し、見やすく整形表示（by区切り＋コメント）
#  ldapi は指定が無ければ総当たりで自動検出
#
#  使用例:
#    ./olcAccess_show.sh --host=ovs-012
#    ./olcAccess_show.sh --host=ovs-009 --ldapi='ldapi://%2Fusr%2Flocal%2Fopenldap-2.6.10%2Fvar%2Frun%2Fldapi'
#    ./olcAccess_show.sh --host=ovs-012 --out=all
#
#  オプション:
#    --host=HOST      対象ホスト名（必須）
#    --ldapi=URI      明示的に ldapi URI を指定（優先）
#    --out=all        整形せず raw 出力（既定: 整形＋コメント）
#----------------------------------------------------------------------
set -Eeuo pipefail
LANG=C
LC_ALL=C

HOST=""; OUT_MODE="pretty"; LDAPI_URI=""
SUFFIX='dc=e-smile,dc=ne,dc=jp'

for arg in "$@"; do
  case "$arg" in
    --host=*) HOST="${arg#*=}" ;;
    --ldapi=*) LDAPI_URI="${arg#*=}" ;;
    --out=*) OUT_MODE="${arg#*=}" ;;
    -h|--help) echo "Usage: $0 --host=HOST [--ldapi=URI] [--out=all]"; exit 0 ;;
    *) echo "[WARN] unknown arg: $arg" ;;
  esac
done

[[ -n "$HOST" ]] || { echo "[ERROR] --host=HOST is required"; exit 1; }

# 自動検出
auto_ldapi() {
  local host="$1"
  local -a guesses=(
    'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Fvar%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Frun%2Fldapi'
  )
  local dyn
  dyn=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$host" "ls -1d /usr/local/openldap-*/var/run/ldapi 2>/dev/null | head -n1") || true
  if [[ -n "$dyn" ]]; then
    local enc="${dyn//\//%2F}"
    guesses=("ldapi://$enc" "${guesses[@]}")
  fi
  local u
  for u in "${guesses[@]}"; do
    if ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$host" "ldapsearch -LLL -Y EXTERNAL -H '$u' -b cn=config dn -o ldif-wrap=no >/dev/null 2>&1"; then
      echo "$u"; return 0
    fi
  done
  echo ""
}

# ldapi 決定
if [[ -z "$LDAPI_URI" ]]; then
  LDAPI_URI="$(auto_ldapi "$HOST")"
  [[ -n "$LDAPI_URI" ]] || { echo "[ERROR] ldapi が見つかりません: $HOST"; exit 1; }
  echo "[INFO] AUTO ldapi: $LDAPI_URI"
fi

echo "[INFO] Fetching olcAccess from $HOST ..."

# DN を取得（mdb/hdb/bdb すべて探索）
DN=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$HOST" bash -s -- "$LDAPI_URI" "$SUFFIX" <<'EOS'
LDAPURI="$1"; SUFFIX="$2"; set -Eeuo pipefail
for o in olcMdbConfig olcHdbConfig olcBdbConfig; do
  DN=$(ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
        "(&(objectClass=$o)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
      | awk "/^dn: /{print substr(\$0,5)}" | head -n1) || true
  [[ -n "$DN" ]] && { echo "$DN"; exit 0; }
done
exit 1
EOS
)

[[ -n "$DN" ]] || { echo "[ERROR] could not determine database DN on $HOST"; exit 1; }
echo "[INFO] DN: $DN"

RAW=$(ssh -o BatchMode=yes -o StrictHostKeyChecking=no "$HOST" \
  "ldapsearch -LLL -Y EXTERNAL -H '$LDAPI_URI' -b '$DN' olcAccess -o ldif-wrap=no" 2>/dev/null)

[[ "$OUT_MODE" == "all" ]] && { echo "$RAW"; exit 0; }

# 整形＋コメント
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


