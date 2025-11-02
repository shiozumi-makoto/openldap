#!/usr/bin/env bash
# ldap_repl_smoketest.sh
# シンプル複製テスト:
#  1) ovs-012(ローカル)の ldapi に管理DNで「ou=Tests」と「cn=repl-<ts>」を追加
#  2) 各ホスト(ldap://host)で該当DNが見えるかリトライしながら確認
#  3) --keep が無ければ、最後に同DNを削除し、削除の複製も確認
#
# 使い方例：
#   BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \
#   BIND_PW='xxxx' \
#   /usr/local/etc/openldap/tools/ldap_repl_smoketest.sh --confirm
#
# オプション:
#   --confirm   実行（未指定ならドライラン表示のみ）
#   --keep      最後にテストエントリを削除しない（残しておく）
#   --hosts="ovs-012 ovs-024 ovs-025 ovs-026"  確認対象ホスト上書き
#   --base="dc=e-smile,dc=ne,dc=jp"             ベースDN上書き
#   --uri-ldapi="ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi"  ldapi上書き
#   --wait=30   反映待ち最大リトライ回数（1秒刻み）
set -Eeuo pipefail

# ===== デフォルト設定 =====
BASE_DN="${BASE_DN:-dc=e-smile,dc=ne,dc=jp}"
BIND_DN="${BIND_DN:-cn=Admin,dc=e-smile,dc=ne,dc=jp}"
BIND_PW="${BIND_PW:-}"
HOSTS_DEF="ovs-012 ovs-024 ovs-025 ovs-026 ovs-002"
HOSTS="${HOSTS:-$HOSTS_DEF}"

# ldapi（ローカル書き込み）
URI_LDAPI_DEFAULT='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
URI_LDAPI="${LDAPURI:-$URI_LDAPI_DEFAULT}"

WAIT_MAX=30
CONFIRM=false
KEEP=false

# ===== オプション処理 =====
for arg in "$@"; do
  case "$arg" in
    --confirm) CONFIRM=true;;
    --keep) KEEP=true;;
    --hosts=*) HOSTS="${arg#*=}";;
    --base=*) BASE_DN="${arg#*=}";;
    --uri-ldapi=*) URI_LDAPI="${arg#*=}";;
    --wait=*) WAIT_MAX="${arg#*=}";;
    --help|-h)
      echo "Usage: $0 [--confirm] [--keep] [--hosts=\"h1 h2 ...\"] [--base=DN] [--uri-ldapi=URI] [--wait=N]"
      exit 0
      ;;
    *) ;;
  esac
done || true

# ===== 前提チェック =====
ts() { date '+%Y-%m-%d %H:%M:%S'; }
echo "[INFO] $(ts) BASE_DN=${BASE_DN}"
echo "[INFO] $(ts) BIND_DN=${BIND_DN}"
echo "[INFO] $(ts) URI_LDAPI=${URI_LDAPI}"
echo "[INFO] $(ts) HOSTS=${HOSTS}"
echo "[INFO] $(ts) WAIT_MAX=${WAIT_MAX}s"
$CONFIRM || echo "[INFO] DRY-RUN: 変更せずに手順だけ表示します。--confirm で実行"

if $CONFIRM && [ -z "$BIND_PW" ]; then
  echo "[ERROR] BIND_PW が未設定です。環境変数 BIND_PW に設定してください。"; exit 1
fi

# ===== テストDN生成 =====
STAMP="$(date '+%Y%m%d%H%M%S')"
CNVAL="repl-${STAMP}"
TEST_OU_DN="ou=Tests,${BASE_DN}"
TEST_DN="cn=${CNVAL},${TEST_OU_DN}"

# 作業LDIF保存先
LDIF_DIR="/usr/local/etc/openldap/ldif/tmp"
mkdir -p "$LDIF_DIR"

OU_LDIF="${LDIF_DIR}/add_ou_Tests.ldif"
ENTRY_LDIF="${LDIF_DIR}/add_${CNVAL}.ldif"
DEL_LDIF="${LDIF_DIR}/del_${CNVAL}.ldif"

# ===== LDIF 作成 =====
cat > "$OU_LDIF" <<'LDIF'
dn: OU_DN_HERE
objectClass: top
objectClass: organizationalUnit
ou: Tests
LDIF
# 置換
sed -i "s#OU_DN_HERE#${TEST_OU_DN}#g" "$OU_LDIF"

cat > "$ENTRY_LDIF" <<LDIF
dn: ${TEST_DN}
objectClass: top
objectClass: organizationalRole
cn: ${CNVAL}
description: replication smoke test created at $(ts) on $(hostname -f)
LDIF

cat > "$DEL_LDIF" <<LDIF
dn: ${TEST_DN}
LDIF

echo "[INFO] 生成LDIF:"
echo "  - $OU_LDIF"
echo "  - $ENTRY_LDIF"
echo "  - $DEL_LDIF (削除用)"

# ===== 1) 追加（ovs-012/ldapi に書き込み） =====
echo "[STEP] ou=Tests の存在を保証（なければ追加）"
echo "  ldapadd -x -D '$BIND_DN' -H '$URI_LDAPI' -f '$OU_LDIF'  # -c 相当で既存でもOK扱い"
if $CONFIRM; then
  # 既存なら "Already exists" で失敗させないため -c
  ldapadd -c -x -D "$BIND_DN" -w "$BIND_PW" -H "$URI_LDAPI" -f "$OU_LDIF" >/dev/null || true
fi

echo "[STEP] テストエントリの追加: $TEST_DN"
echo "  ldapadd -x -D '$BIND_DN' -H '$URI_LDAPI' -f '$ENTRY_LDIF'"
if $CONFIRM; then
  ldapadd -x -D "$BIND_DN" -w "$BIND_PW" -H "$URI_LDAPI" -f "$ENTRY_LDIF"
fi

# ===== 2) 各ホストで存在確認（リトライ） =====
echo "[STEP] 複製反映の確認 (最大 ${WAIT_MAX}s 待機)"
declare -A seen
for h in $HOSTS; do seen["$h"]=0; done

for ((i=1; i<=WAIT_MAX; i++)); do
  all_ok=true
  for h in $HOSTS; do
    if [ "${seen[$h]}" -eq 1 ]; then
      continue
    fi
    # 存在確認
    if ldapsearch -LLL -x -H "ldap://$h" -b "$TEST_DN" dn >/dev/null 2>&1; then
      echo "[OK] ${h}: found $TEST_DN"
      seen["$h"]=1
      # 参考: contextCSN も一緒に出す
      ldapsearch -LLL -x -H "ldap://$h" -s base -b "$BASE_DN" contextCSN | sed "s/^/[CSN][$h] /"
    else
      echo "[..] ${h}: まだ未反映 (try ${i}/${WAIT_MAX})"
      all_ok=false
    fi
  done
  $all_ok && break
  sleep 1
done

# 判定
fail_list=()
for h in $HOSTS; do
  if [ "${seen[$h]}" -ne 1 ]; then
    fail_list+=("$h")
  fi
done

if [ "${#fail_list[@]}" -gt 0 ]; then
  echo "[WARN] 反映を確認できなかったホスト: ${fail_list[*]}"
  if ! $CONFIRM; then
    echo "[INFO] DRY-RUN のため未追加。--confirm で実際に動作させてください。"
  fi
else
  echo "[SUCCESS] すべてのホストで $TEST_DN を確認できました。"
fi

# ===== 3) 後片付け（削除） =====
if $KEEP; then
  echo "[INFO] --keep 指定のため削除せず終了。 DN: $TEST_DN"
  exit 0
fi

echo "[STEP] テストエントリ削除（ローカル ldapi へ）"
echo "  ldapdelete -x -D '$BIND_DN' -H '$URI_LDAPI' '$TEST_DN'"
if $CONFIRM; then
  ldapdelete -x -D "$BIND_DN" -w "$BIND_PW" -H "$URI_LDAPI" "$TEST_DN"
fi

echo "[STEP] 削除の複製確認 (最大 ${WAIT_MAX}s)"
declare -A gone
for h in $HOSTS; do gone["$h"]=0; done

for ((i=1; i<=WAIT_MAX; i++)); do
  all_ok=true
  for h in $HOSTS; do
    if [ "${gone[$h]}" -eq 1 ]; then
      continue
    fi
    if ldapsearch -LLL -x -H "ldap://$h" -b "$TEST_DN" dn >/dev/null 2>&1; then
      echo "[..] ${h}: まだ残っている (try ${i}/${WAIT_MAX})"
      all_ok=false
    else
      echo "[OK] ${h}: 削除反映を確認（$TEST_DN が見えない）"
      gone["$h"]=1
      ldapsearch -LLL -x -H "ldap://$h" -s base -b "$BASE_DN" contextCSN | sed "s/^/[CSN][$h] /"
    fi
  done
  $all_ok && break
  sleep 1
done

fail_del=()
for h in $HOSTS; do
  if [ "${gone[$h]}" -ne 1 ]; then
    fail_del+=("$h")
  fi
done

if [ "${#fail_del[@]}" -gt 0 ]; then
  echo "[WARN] 削除の反映を確認できなかったホスト: ${fail_del[*]}"
  exit 1
fi

echo "[SUCCESS] すべてのホストで削除の複製も確認できました。"
exit 0

