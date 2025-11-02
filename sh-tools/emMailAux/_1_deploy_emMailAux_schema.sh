#!/usr/bin/env bash
set -Eeuo pipefail

# ================== 設定（環境変数で上書き可） ==================
SSH_USER="${SSH_USER:-root}"
HOSTS=(${HOSTS_OVERRIDE:-ovs-002 ovs-012 ovs-024 ovs-025 ovs-026})

# データDBのサフィックス（DB DN 自動特定に利用）
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"

# mailAlternateAddress に index を追加するなら 1（eq,sub）
APPLY_INDEX="${APPLY_INDEX:-1}"

# slapindex を実行（停止→再構築→起動）するなら 1（メンテ時間に）
APPLY_REINDEX="${APPLY_REINDEX:-0}"

# リモートで使う一時ファイル
REMOTE_DIR="${REMOTE_DIR:-/usr/local/etc/openldap/sh-tools/emMailAux}"
REMOTE_ADD_LDIF="${REMOTE_DIR}/emMailAux_add.ldif"
REMOTE_REP_LDIF="${REMOTE_DIR}/emMailAux_replace.ldif"

# SSH 共通オプション
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5)


# ================== 内包LDIF（add / replace） ==================
# OK: ヒアドキュメント→コマンド置換
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
olcObjectClasses: ( 1.3.6.1.4.1.55555.1.2
  NAME 'emMailAux'
  DESC 'Aux class to hold alternate email addresses'
  SUP top
  AUXILIARY
  MAY ( mailAlternateAddress ) )
LDIF
)

LDIF_REP=$(cat <<'LDIF'
dn: cn=emMailAux,cn=schema,cn=config
changetype: modify
replace: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1
  NAME 'mailAlternateAddress'
  DESC 'Additional email addresses for a person'
  EQUALITY caseIgnoreIA5Match
  SUBSTR caseIgnoreIA5SubstringsMatch
  SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )
-
replace: olcObjectClasses
olcObjectClasses: ( 1.3.6.1.4.1.55555.1.2
  NAME 'emMailAux'
  DESC 'Aux class to hold alternate email addresses'
  SUP top
  AUXILIARY
  MAY ( mailAlternateAddress ) )
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

  log "[$H] LDIF をリモートに配置（add/replace）"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "cat > '${REMOTE_ADD_LDIF}' <<'LDIF'
${LDIF_ADD}
LDIF"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "cat > '${REMOTE_REP_LDIF}' <<'LDIF'
${LDIF_REP}
LDIF"

  # ---------- 1) スキーマの実体確認 ----------
  log "[$H] subschema で mailAlternateAddress の存在確認..."
  if ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
       "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | grep -q \"NAME 'mailAlternateAddress'\""
  then
    log "[$H] mailAlternateAddress は既に認識済（subschemaに存在）"
  else
    log "[$H] subschema に未登録。dn 実在チェック..."
    if ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
         "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=emMailAux,cn=schema,cn=config' -s base dn >/dev/null 2>&1"
    then
      log "[$H] cn=emMailAux は存在 → 壊れ定義の可能性。replace で修復します。"
      ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
         "ldapmodify -Y EXTERNAL -H ldapi:/// -f '${REMOTE_REP_LDIF}'"
    else
      log "[$H] cn=emMailAux は未作成 → ldapadd で投入します。"
      ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
         "ldapadd -Y EXTERNAL -H ldapi:/// -f '${REMOTE_ADD_LDIF}'"
    fi

    # 最終確認
    if ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
         "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | grep -q \"NAME 'mailAlternateAddress'\""
    then
      log "[$H] 修復/投入 OK（subschema に認識されました）"
    else
      die "[$H] スキーマ修復/投入後も subschema に出現せず。手動確認が必要です。"
    fi
  fi

  # ---------- 2) インデックス追加（任意） ----------
  if [ "$APPLY_INDEX" = "1" ]; then
    log "[$H] olcDbIndex に mailAlternateAddress(eq,sub) を追加（または統合）します"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" bash -s <<'EOS'
set -Eeuo pipefail
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"

# DB DN を suffix から特定
DB_DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config "(olcSuffix=${SUFFIX})" dn | awk '/^dn: /{print substr($0,5)}')
[ -n "$DB_DN" ] || { echo "[ERR] DB DN 取得失敗（SUFFIX=${SUFFIX})"; exit 1; }

# 既に index があるか？
if ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DB_DN" olcDbIndex | grep -q '^olcDbIndex: mailAlternateAddress'; then
  echo "[INFO] 既に mailAlternateAddress の index が設定済み。スキップ"
  exit 0
fi

# まず add を試す
TMP_ADD=/tmp/add_index_mailAlternate.ldif
cat >"$TMP_ADD" <<LDIF
dn: $DB_DN
changetype: modify
add: olcDbIndex
olcDbIndex: mailAlternateAddress eq,sub
LDIF

if ldapmodify -Y EXTERNAL -H ldapi:/// -f "$TMP_ADD"; then
  echo "[INFO] olcDbIndex 追加 OK（add）"
  exit 0
fi

# add が失敗 → 現状を取得して replace 統合
CURR=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DB_DN" olcDbIndex | awk -F': ' '/^olcDbIndex:/{print $2}')
MERGED=$( (printf "%s\n" "$CURR"; echo "mailAlternateAddress eq,sub") | awk '!a[$0]++' )

TMP_REP=/tmp/replace_index_mailAlternate.ldif
{
  echo "dn: $DB_DN"
  echo "changetype: modify"
  echo "replace: olcDbIndex"
  while IFS= read -r line; do
    [ -n "$line" ] && echo "olcDbIndex: $line"
  done <<< "$MERGED"
} > "$TMP_REP"

ldapmodify -Y EXTERNAL -H ldapi:/// -f "$TMP_REP"
echo "[INFO] olcDbIndex 置換 OK（replace統合）"
EOS
  fi

  # ---------- 3) 任意：再インデックス ----------
  if [ "$APPLY_REINDEX" = "1" ]; then
    log "[$H] slapindex を実行します（停止→再構築→起動）"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" bash -s <<'EOS'
set -Eeuo pipefail
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"
systemctl stop slapd
slapindex -b "$SUFFIX"
systemctl start slapd
echo "[INFO] slapindex 実行完了"
EOS
  fi

  log "==== [$H] 完了 ===="
done

log "★ すべてのホストで処理が終了しました"
log "確認例:"
log "  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributeTypes | grep \"NAME 'mailAlternateAddress'\""
log "  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b \"olcDatabase={X}mdb,cn=config\" olcDbIndex | grep mailAlternateAddress"
if [ "$APPLY_REINDEX" = "0" ]; then
  log "※ 既存データに対する実索引は slapindex 実行で作成されます（必要に応じてメンテ時間に実施）。"
fi


