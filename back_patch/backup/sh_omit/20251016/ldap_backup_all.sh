# /usr/local/etc/openldap/tools/ldap_backup_all.sh
# - MDB番号を複数手段で自動検出（olcSuffix→olcDatabase=*mdb→ファイル名探索→最後に1）
# - 行数は実数表示
# - 失敗時フォールバック (-b BASE) あり
set -Eeuo pipefail

HOSTS=(ovs-012 ovs-024 ovs-025 ovs-026 ovs-002)
CFG=/usr/local/etc/openldap/slapd.d
URI_LDAPI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
BASE='dc=e-smile,dc=ne,dc=jp'

for h in "${HOSTS[@]}"; do
  echo "==$h=="
  ssh "$h" bash -s <<'EOS'
set -Eeuo pipefail
CFG=/usr/local/etc/openldap/slapd.d
URI_LDAPI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
BASE='dc=e-smile,dc=ne,dc=jp'
OUT=/root/ldap-backup; ts=$(date +%F_%H%M%S)
mkdir -p "$OUT"

# 0) ヘルパー：MDB番号検出
detect_mdbn() {
  local n=""
  # A. olcSuffix から該当DBのDNを取る → {N}抽出
  n=$(ldapsearch -LLL -Q -Y EXTERNAL -H "$URI_LDAPI" -b cn=config \
        "(olcSuffix=$BASE)" dn 2>/dev/null \
      | awk -F'[{}]' '/^dn: olcDatabase=\{[0-9]+\}mdb,cn=config$/{print $2; exit}')
  [[ -n "$n" ]] && { echo "$n"; return; }

  # B. mdb DBのDNを直接列挙して最初のものを採用（単一DB前提）
  n=$(ldapsearch -LLL -Q -Y EXTERNAL -H "$URI_LDAPI" -b cn=config \
        '(olcDatabase=*mdb)' dn 2>/dev/null \
      | awk -F'[{}]' '/^dn: olcDatabase=\{[0-9]+\}mdb/{print $2; exit}')
  [[ -n "$n" ]] && { echo "$n"; return; }

  # C. slapd.d 配下のファイル名から推測（例: olcDatabase={2}mdb.ldif）
  n=$(ls "$CFG"/cn=config/olcDatabase=\{*}mdb.ldif 2>/dev/null \
      | sed -n 's/.*olcDatabase={\([0-9]\+\)}mdb\.ldif$/\1/p' | head -n1)
  [[ -n "$n" ]] && { echo "$n"; return; }

  # D. 最後の砦として 1
  echo 1
}

# 1) config backup
if slapcat -n 0 -F "$CFG" > "$OUT/config-$ts.ldif" 2>/dev/null; then
  :
else
  echo "[WARN] slapcat -n 0 失敗 → ldapsearch(EXTERNAL)でフォールバック"
  ldapsearch -LLL -Q -Y EXTERNAL -H "$URI_LDAPI" -b cn=config > "$OUT/config-$ts.ldif"
fi

# 2) mdb 番号検出
MDBN="$(detect_mdbn || true)"
if [[ -z "$MDBN" ]]; then
  echo "[WARN] mdb の番号が取得できません → -n 1 を試行"
  MDBN=1
fi

# 3) data backup
if slapcat -n "$MDBN" -F "$CFG" > "$OUT/data-$ts.ldif" 2>/dev/null; then
  :
else
  echo "[WARN] slapcat -n $MDBN 失敗 → -b $BASE でフォールバック"
  slapcat -b "$BASE" -F "$CFG" > "$OUT/data-$ts.ldif"
fi

# 4) 行数（実数値）を表示
cfg_lines=$(wc -l < "$OUT/config-$ts.ldif" | tr -d ' ')
dat_lines=$(wc -l < "$OUT/data-$ts.ldif"   | tr -d ' ')
echo "[INFO] config lines: $cfg_lines"
echo "[INFO] data   lines: $dat_lines"

# 5) 圧縮（任意）
gzip -f "$OUT/config-$ts.ldif" "$OUT/data-$ts.ldif" && echo "[INFO] gzip 完了"
EOS
done


