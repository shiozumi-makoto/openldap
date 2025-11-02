#!/usr/bin/env bash
set -Eeuo pipefail

# ================== 基本設定（必要なら環境変数で上書き可） ==================
SSH_USER="${SSH_USER:-root}"
HOSTS=(${HOSTS_OVERRIDE:-ovs-002 ovs-012 ovs-024 ovs-025 ovs-026})

# スクリプト自身のディレクトリを起点に LDIF を参照
SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LDIF_LOCAL="${LDIF_LOCAL:-${SCRIPT_DIR}/emMailAux_fix.ldif}"

# リモート側も同じディレクトリ階層に配置する（無ければ作成）
REMOTE_DIR="${REMOTE_DIR:-/usr/local/etc/openldap/sh-tools/emMailAux}"
REMOTE_LDIF="${REMOTE_LDIF:-${REMOTE_DIR}/emMailAux.ldif}"

# mailAlternateAddress に index を追加する場合は 1（eq,sub）
APPLY_INDEX="${APPLY_INDEX:-0}"

# SSH オプション（初回ホスト鍵などで止まらないように）
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5)

# ================== 共通関数 ==================
log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

# ================== 事前チェック ==================
need scp; need ssh
[ -f "$LDIF_LOCAL" ] || die "LDIF が見つかりません: $LDIF_LOCAL"

# ================== 展開ループ ==================
for H in "${HOSTS[@]}"; do
  log "==== [$H] emMailAux スキーマ適用開始 ===="

  # リモートディレクトリ作成
  log "[$H] リモートディレクトリ作成: ${REMOTE_DIR}"
  ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" "mkdir -p '${REMOTE_DIR}'"

  # LDIF 配置
  log "[$H] LDIF 転送: ${LDIF_LOCAL} -> ${REMOTE_LDIF}"
  scp -q "${LDIF_LOCAL}" "${SSH_USER}@${H}:${REMOTE_LDIF}"

  # 既存スキーマの有無チェック
  log "[$H] 既存スキーマ存在チェック..."
  if ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
    "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config '(cn=emMailAux)' cn >/dev/null 2>&1"
  then
    log "[$H] 既に emMailAux が存在します（スキップ）"
  else
    # 追加
    log "[$H] スキーマ追加 ldapadd 実行..."
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
      "ldapadd -Y EXTERNAL -H ldapi:/// -f '${REMOTE_LDIF}'"

    # 検証
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" \
      "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config '(cn=emMailAux)' olcAttributeTypes olcObjectClasses | grep -E 'mailAlternateAddress|emMailAux' >/dev/null" \
      || die "[$H] スキーマ適用検証に失敗しました"
    log "[$H] スキーマ追加 OK"
  fi

  # 任意：インデックス追加
  if [ "$APPLY_INDEX" = "1" ]; then
    log "[$H] olcDbIndex に mailAlternateAddress(eq,sub) を追加します"
    ssh "${SSH_OPTS[@]}" "${SSH_USER}@${H}" bash -s <<'EOS'
set -Eeuo pipefail
DB_DN=$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config '(olcSuffix=dc=e-smile,dc=ne,dc=jp)' dn | awk '/^dn: /{print substr($0,5)}')
[ -n "$DB_DN" ] || { echo "[ERR] DB DN 取得失敗"; exit 1; }

if ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DB_DN" olcDbIndex | grep -q '^olcDbIndex: mailAlternateAddress'; then
  echo "[INFO] 既に mailAlternateAddress の index が設定済み。スキップ"
else
  cat >/tmp/add_index_mailAlternate.ldif <<LDIF
dn: $DB_DN
changetype: modify
add: olcDbIndex
olcDbIndex: mailAlternateAddress eq,sub
LDIF
  ldapmodify -Y EXTERNAL -H ldapi:/// -f /tmp/add_index_mailAlternate.ldif
  echo "[INFO] olcDbIndex 追加 OK（reindex 推奨）"
fi
EOS
  fi

  log "==== [$H] 完了 ===="
done

# 整合性ハッシュ（任意）：全ノードで同一になっているか簡易確認
log "★ すべてのホストで処理が終了しました"
log "（任意）整合性チェック例："
log "  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config '(cn=emMailAux)' olcAttributeTypes olcObjectClasses | sha1sum"
if [ "$APPLY_INDEX" = "1" ]; then
  log "※ インデックス追加時は、負荷の少ない時間帯に slapindex 実行をご検討ください。"
fi

