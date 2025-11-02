#!/usr/bin/env bash
set -Eeuo pipefail

# ================== 設定（環境変数で上書き可） ==================
SSH_USER="${SSH_USER:-root}"
HOSTS=(${HOSTS_OVERRIDE:-ovs-002 ovs-012 ovs-024 ovs-025 ovs-026})

# データDBのサフィックス（DB DN 自動特定に利用）
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"

# インデックス付与（1で実施）
APPLY_INDEX="${APPLY_INDEX:-1}"

# slapindex 実行（1で実施、属性限定）
APPLY_REINDEX="${APPLY_REINDEX:-0}"

# リモートで使う一時ファイル
REMOTE_DIR="${REMOTE_DIR:-/usr/local/etc/openldap/sh-tools/emMailAux}"
REMOTE_ADD_LDIF="${REMOTE_DIR}/emMailAux_add.ldif"
REMOTE_MOD_LDIF="${REMOTE_DIR}/emMailAux_mod.ldif"

# SSH 共通オプション
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5)

# ================== 内包LDIF（新規作成用 / 追加入り用） ==================
# 新規（cn=emMailAux が無い場合の作成用）
LDIF_ADD=$(cat <<'LDIF'
dn: cn=emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: emMailAux
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1
  NAME 'mailAlternateAddress'
  DESC 'Additional email addresses for a person'
  EQUALITY caseIgnoreIA5Match
  SUBSTR caseIgnoreIA5SubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.3
  NAME 'displayNameOrder'
  DESC 'Sortable display name for address book'
  EQUALITY caseIgnoreMatch
  ORDERING caseIgnoreOrderingMatch
  SUBSTR caseIgnoreSubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.15 )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.31
  NAME 'displayOrderInt'
  DESC 'Integer order for display sorting'
  EQUALITY integerMatch
  ORDERING integerOrderingMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.27
  SINGLE-VALUE )
olcObjectClasses: ( 1.3.6.1.4.1.55555.1.2
  NAME 'emMailAux'
  DESC 'Aux class to hold alternate email addresses'
  SUP top
  AUXILIARY
  MAY ( mailAlternateAddress $ displayNameOrder $ displayOrderInt ) )
LDIF
)

# 追加入り（既存の emMailAux に “足すだけ”）
LDIF_MOD=$(cat <<'LDIF'
dn: cn=emMailAux,cn=schema,cn=config
changetype: modify
add: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1
  NAME 'mailAlternateAddress'
  DESC 'Additional email addresses for a person'
  EQUALITY caseIgnoreIA5Match
  SUBSTR caseIgnoreIA5SubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )
-
add: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.3
  NAME 'displayNameOrder'
  DESC 'Sortable display name for address book'
  EQUALITY caseIgnoreMatch
  ORDERING caseIgnoreOrderingMatch
  SUBSTR caseIgnoreSubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.15 )
-
add: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.31
  NAME 'displayOrderInt'
  DESC 'Integer order for display sorting'
  EQUALITY integerMatch
  ORDERING integerOrderingMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.27
  SINGLE-VALUE )
-
add: olcObjectClasses
olcObjectClasses: ( 1.3.6.1.4.1.55555.1.2
  NAME 'emMailAux'
  DESC 'Aux class to hold alternate email addresses'
  SUP top
  AUXILIARY
  MAY ( mailAlternateAddress $ displayNameOrder $ displayOrderInt ) )
LDIF
)

# ================== 共通関数 ==================
log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

# ================== 事前チェック ==================
need ssh

# ================== 展開ループ ==================
for H in "${HOSTS[@]}"; do
  log "==== [$H] emMailAux スキーマ適用開始 ===="

  # リモート：ディレクトリ作成＆LDIF配置
  log "[$H] リモートディレクトリ作成: ${REMOTE_DIR}"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "mkdir -p '${REMOTE_DIR}'"

  log "[$H] LDIF をリモートに配置（add/mod）"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "cat > '${REMOTE_ADD_LDIF}' <<'LDIF'
${LDIF_ADD}
LDIF"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "cat > '${REMOTE_MOD_LDIF}' <<'LDIF'
${LDIF_MOD}
LDIF"

  # ---------- 1) emMailAux の DN を特定 ----------
  SCHEMA_DN=$(
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
      "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=schema,cn=config' '(cn=*emMailAux*)' dn | awk '/^dn: /{print substr(\$0,5); exit}'" \
    || true
  )

  if [ -z "${SCHEMA_DN}" ]; then
    log "[$H] emMailAux が存在しないため新規追加（ldapadd）"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
      "ldapadd -Y EXTERNAL -H ldapi:/// -f '${REMOTE_ADD_LDIF}'"
    # 再取得
    SCHEMA_DN=$(
      ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
        "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=schema,cn=config' '(cn=*emMailAux*)' dn | awk '/^dn: /{print substr(\$0,5); exit}'"
    )
  else
    log "[$H] emMailAux は存在（${SCHEMA_DN}）→ 安全に add 差分を適用"
    # DN を正規化（番号付きDNに合わせて差分適用）
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
      "sed -e '1s#^dn: .*#dn: ${SCHEMA_DN}#' '${REMOTE_MOD_LDIF}' | ldapmodify -c -Y EXTERNAL -H ldapi:///"
  fi

  # ---------- 2) subschema に2属性が認識されているか ----------
  if ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
       "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | grep -q \"NAME 'mailAlternateAddress'\" \
        && ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | grep -q \"NAME 'displayOrderInt'\""
  then
    log "[$H] subschema に mailAlternateAddress / displayOrderInt を確認（OK）"
  else
    die "[$H] subschema で属性が認識されていません。手動確認が必要です。"
  fi

  # ---------- 3) インデックス追加（任意） ----------
  if [ "$APPLY_INDEX" = "1" ]; then
    log "[$H] olcDbIndex に mailAlternateAddress(eq,sub) / displayOrderInt(eq) を追加（または統合）します"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" SUFFIX="${SUFFIX}" bash -s <<'EOS'
set -Eeuo pipefail
DB_DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config "(olcSuffix=${SUFFIX})" dn | awk '/^dn: /{print substr($0,5)}')
[ -n "$DB_DN" ] || { echo "[ERR] DB DN 取得失敗（SUFFIX=${SUFFIX})"; exit 1; }

curr=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DB_DN" olcDbIndex | awk -F': ' '/^olcDbIndex:/{print $2}')
need_add_ma=1
need_add_do=1
grep -qi '^mailAlternateAddress\b' <<<"$curr" && need_add_ma=0 || true
grep -qi '^displayOrderInt\b'     <<<"$curr" && need_add_do=0 || true

if [ $need_add_ma -eq 0 ] && [ $need_add_do -eq 0 ]; then
  echo "[INFO] 既に両方の index が設定済み。スキップ"
  exit 0
fi

# まず add を試す（ある方だけ個別に add）
tmp=/tmp/add_index.ldif
echo "dn: $DB_DN" >"$tmp"
echo "changetype: modify" >>"$tmp"
if [ $need_add_ma -eq 1 ]; then
  echo "add: olcDbIndex" >>"$tmp"
  echo "olcDbIndex: mailAlternateAddress eq,sub" >>"$tmp"
  echo "-" >>"$tmp"
fi
if [ $need_add_do -eq 1 ]; then
  echo "add: olcDbIndex" >>"$tmp"
  echo "olcDbIndex: displayOrderInt eq" >>"$tmp"
fi

if ldapmodify -Y EXTERNAL -H ldapi:/// -f "$tmp"; then
  echo "[INFO] olcDbIndex 追加 OK（add）"
  exit 0
fi

# add が失敗 → 置換で統合
merged=$( (printf "%s\n" "$curr"; [ $need_add_ma -eq 1 ] && echo "mailAlternateAddress eq,sub"; [ $need_add_do -eq 1 ] && echo "displayOrderInt eq") | awk 'NF&&!a[tolower($0)]++' )
tmp=/tmp/replace_index.ldif
{
  echo "dn: $DB_DN"
  echo "changetype: modify"
  echo "replace: olcDbIndex"
  while IFS= read -r line; do
    [ -n "$line" ] && echo "olcDbIndex: $line"
  done <<< "$merged"
} > "$tmp"

ldapmodify -Y EXTERNAL -H ldapi:/// -f "$tmp"
echo "[INFO] olcDbIndex 置換 OK（replace統合）"
EOS
  fi

  # ---------- 4) 任意：属性限定の再インデックス ----------
  if [ "$APPLY_REINDEX" = "1" ]; then
    log "[$H] slapindex を属性限定で実行（mailAlternateAddress / displayOrderInt）"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" SUFFIX="${SUFFIX}" bash -s <<'EOS'
set -Eeuo pipefail
systemctl stop slapd
slapindex -b "$SUFFIX" mailAlternateAddress
slapindex -b "$SUFFIX" displayOrderInt
systemctl start slapd
echo "[INFO] slapindex 実行完了（属性限定）"
EOS
  fi

  log "==== [$H] 完了 ===="
done

log "★ すべてのホストで処理が終了しました"
log "確認例:"
log "  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | egrep \"NAME 'mailAlternateAddress'|'displayOrderInt'\""
log "  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b \"olcDatabase={X}mdb,cn=config\" olcDbIndex | egrep -i 'mailAlternateAddress|displayOrderInt'"
if [ "$APPLY_REINDEX" = "0" ]; then
  log "※ 既存データに対する実索引は slapindex 実行で作成されます（必要に応じてメンテ時間に実施）。"
fi
