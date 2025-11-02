#!/usr/bin/env bash
# ldap_repl_quickdiag.sh (v2)
# 各ホストの mdb データベース DN を自動特定し、olcMirrorMode / olcSyncrepl / syncprov overlay を一覧化

set -Eeuo pipefail

HOSTS="${HOSTS:-ovs-012 ovs-024 ovs-025 ovs-026}"
BASE_DN="${BASE_DN:-dc=e-smile,dc=ne,dc=jp}"
LDAPI_PATH='/usr/local/var/run/ldapi'   # 皆さんの標準パス
LDAPI_URI="ldapi://%2F${LDAPI_PATH//\//%2F}"

TEST_DN_HINT="$(ls -1t /usr/local/etc/openldap/ldif/tmp/add_repl-*.ldif 2>/dev/null | head -n1 | awk -F'add_' '{print $2}' | sed 's/\.ldif$//')"
if [[ -n "${TEST_DN_HINT:-}" ]]; then
  TEST_DN="cn=${TEST_DN_HINT},ou=Tests,${BASE_DN}"
else
  TEST_DN=""
fi

echo "== REPL QUICK DIAG v2 =="
echo "HOSTS:   $HOSTS"
echo "BASE_DN: $BASE_DN"
[[ -n "$TEST_DN" ]] && echo "TEST_DN: $TEST_DN"
echo

diag_host () {
  local h="$1"
  echo "====== $h ======"

  # 0) ネット疎通
  if ldapsearch -LLL -x -H "ldap://$h" -s base -b "" namingContexts >/dev/null 2>&1; then
    echo "[OK] ldap://$h namingContexts 取得"
  else
    echo "[ERR] ldap://$h に接続できません（ポート/FW/daemon確認）"
  fi

  # 1) contextCSN
  ldapsearch -LLL -x -H "ldap://$h" -s base -b "$BASE_DN" contextCSN 2>/dev/null | sed "s/^/[CSN][$h] /" || true

  # 2) テストDN有無
  if [[ -n "$TEST_DN" ]]; then
    if ldapsearch -LLL -x -H "ldap://$h" -s base -b "$TEST_DN" dn >/dev/null 2>&1; then
      echo "[HIT] $h has $TEST_DN"
    else
      echo "[MISS] $h does NOT have $TEST_DN"
    fi
  fi

  # 3) cn=config から mdb の DN を自動特定し、設定を抽出（ssh で slapcat -n 0）
  ssh -o BatchMode=yes -o ConnectTimeout=5 "$h" "BASE_DN='$BASE_DN' bash -s" <<'EOS' | sed "s/^/[CFG]['"$h"'] /" || echo "[WARN] $h: slapcat -n0 失敗"
set -Eeuo pipefail
CONF="/usr/local/etc/openldap/slapd.d"

# (a) mdb DN を olcSuffix で探す（番号は可変）
MDB_DN=$(slapcat -n 0 -F "$CONF" 2>/dev/null | awk -v base="$BASE_DN" '
  BEGIN{dn=""}
  /^dn: olcDatabase=\{[0-9]+\}mdb,cn=config$/ {dn=$0}
  /^olcSuffix:[[:space:]]*/ {gsub(/^olcSuffix:[[:space:]]*/,""); if ($0==base && dn!="") {print dn; dn=""}}
')

echo "MDB_DN: ${MDB_DN:-<not found>}"

# (b) olcMirrorMode / olcSyncrepl を表示
if [[ -n "$MDB_DN" ]]; then
  slapcat -n 0 -F "$CONF" 2>/dev/null | awk -v dn="$MDB_DN" '
    BEGIN{show=0}
    $0==dn {show=1; print "-- mdb block start --"; print; next}
    show && /^dn: / {show=0; print "-- mdb block end --"}
    show && (/^olcMirrorMode:|^olcSyncrepl:|^olcAccess:|^olcLimits:/) {print}
    END{if(show) print "-- mdb block end --"}
  '
  # (c) syncprov overlay の有無（mdb の下に overlay entry が居る）
  slapcat -n 0 -F "$CONF" 2>/dev/null | awk -v dn="$MDB_DN" '
    BEGIN{want=0}
    $0==dn {want=1; next}
    want && /^dn: olcOverlay=\{[0-9]+\}syncprov,/ {print "-- overlay syncprov --"; print; overlay=1}
    END{if (want && overlay!=1) print "-- overlay syncprov: <missing> --"}
  '
fi

# (d) serverID 一覧
slapcat -n 0 -F "$CONF" 2>/dev/null | awk '/^olcServerID:/ {print}'
EOS

  echo
}

for h in $HOSTS; do
  diag_host "$h"
done

cat <<'HINT'
== HINT ==
- 期待
  * MDB_DN が全ホストで特定できる
  * mdb block に `olcMirrorMode: TRUE` がある
  * mdb block に `olcSyncrepl:` が必要本数（他ノード分）ある
  * overlay syncprov が存在する
- 無い場合
  * `olcMirrorMode` が無/FALSE → TRUE に設定
  * syncprov overlay が無い → 追加
  * olcSyncrepl が足りない/不正 → 置き換え（全置換）
HINT


