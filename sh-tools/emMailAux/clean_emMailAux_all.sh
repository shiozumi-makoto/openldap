#!/usr/bin/env bash
# clean_emMailAux_all.sh
# 指定ホスト群で emMailAux スキーマとその参照を完全削除する
set -Eeuo pipefail

# ===== 設定 =====
SSH_USER="${SSH_USER:-root}"
HOSTS="${HOSTS:-ovs-002 ovs-024 ovs-025 ovs-026}"
BASE_DN="${BASE_DN:-dc=e-smile,dc=ne,dc=jp}"

# 動作モード
DRY_RUN="${DRY_RUN:-0}"            # 1=ドライラン（変更系は echo のみ）
CLEAN_DIT="${CLEAN_DIT:-1}"        # 1=DITから displayOrderInt / emMailAux を外す
CLEAN_INDEX="${CLEAN_INDEX:-1}"    # 1=cn=config の olcDbIndex / olcSssVlvConfig から displayOrderInt を外す
DROP_SCHEMA="${DROP_SCHEMA:-1}"    # 1=スキーマ分割削除 → エントリ削除

run_remote() {
  local host="$1" script="$2"
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "---- [DRY] $host ----"
    echo "$script"
  else
    echo "---- [$host] run ----"
    ssh -o StrictHostKeyChecking=no -l "$SSH_USER" "$host" 'bash -s' <<<"$script"
  fi
}

for H in $HOSTS; do
  REMOTE_SCRIPT=$(cat <<'EOS'
set -Eeuo pipefail
BASE_DN='"$BASE_DN"'

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
do_modify(){ if ldapmodify -Y EXTERNAL -H ldapi:/// -f "$1"; then log "apply: $1"; else log "WARN: ldapmodify failed: $1"; fi; }
do_delete(){ if ldapdelete -Y EXTERNAL -H ldapi:/// "$1"; then log "delete: $1"; else log "WARN: ldapdelete failed: $1"; fi; }

log "=== START emMailAux clean ==="
log "BaseDN: ${BASE_DN}"

# 0) slapd 稼働確認（簡易）
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" -s base dn >/dev/null || { echo "ERROR: slapd(ldapi) not応答"; exit 1; }

# 1) 参照チェック
mapfile -t DNs_attr < <(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" '(displayOrderInt=*)' dn | awk '/^dn: /{print substr($0,5)}')
mapfile -t DNs_oc   < <(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" '(objectClass=emMailAux)' dn | awk '/^dn: /{print substr($0,5)}')

log "Found entries with displayOrderInt: ${#DNs_attr[@]}"
log "Found entries with objectClass=emMailAux: ${#DNs_oc[@]}"

# 2) DIT のクリーンアップ
if [[ '"$CLEAN_DIT"' == "1" ]]; then
  # displayOrderInt 削除
  if [[ ${#DNs_attr[@]} -gt 0 ]]; then
    tmp=/tmp/del_displayOrderInt.$$; : > "$tmp"
    for dn in "${DNs_attr[@]}"; do
      printf 'dn: %s\nchangetype: modify\ndelete: displayOrderInt\n\n' "$dn" >> "$tmp"
    done
    do_modify "$tmp"
    rm -f "$tmp"
  fi

  # emMailAux OC 削除
  if [[ ${#DNs_oc[@]} -gt 0 ]]; then
    tmp=/tmp/del_emMailAux_oc.$$; : > "$tmp"
    for dn in "${DNs_oc[@]}"; do
      printf 'dn: %s\nchangetype: modify\ndelete: objectClass\nobjectClass: emMailAux\n\n' "$dn" >> "$tmp"
    done
    do_modify "$tmp"
    rm -f "$tmp"
  fi
fi

# 3) cn=config の参照削除（index/SSSVLV/ACL）
if [[ '"$CLEAN_INDEX"' == "1" ]]; then
  # 対象 DB の DN を拾う（olcDbIndexに displayOrderInt を含む）
  while IFS= read -r line; do DB_DN="$line"
    # そのDBで displayOrderInt を含むインデックス値を全取得 → 1つずつ delete
    mapfile -t IDXVALS < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DB_DN" 'olcDbIndex=*displayOrderInt*' olcDbIndex | awk -F': ' '/^olcDbIndex: /{print $2}')
    for val in "${IDXVALS[@]}"; do
      tmp=/tmp/del_dbindex.$$; cat >"$tmp" <<LDIF
dn: $DB_DN
changetype: modify
delete: olcDbIndex
olcDbIndex: $val
LDIF
      do_modify "$tmp"
      rm -f "$tmp"
    done
  done < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" 'olcDbIndex=*displayOrderInt*' dn | awk '/^dn: /{print substr($0,5)}')

  # SSSVLV のキーから displayOrderInt を外す
  while IFS= read -r OD; do
    mapfile -t VCONF < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$OD" 'olcSssVlvConfig=*displayOrderInt*' olcSssVlvConfig | awk -F': ' '/^olcSssVlvConfig: /{print $2}')
    for v in "${VCONF[@]}"; do
      tmp=/tmp/del_sssvlv.$$; cat >"$tmp" <<LDIF
dn: $OD
changetype: modify
delete: olcSssVlvConfig
olcSssVlvConfig: $v
LDIF
      do_modify "$tmp"
      rm -f "$tmp"
    done
  done < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" 'olcSssVlvConfig=*displayOrderInt*' dn | awk '/^dn: /{print substr($0,5)}')

  # ACL に displayOrderInt が直接書かれていた場合（まれ）
  while IFS= read -r AD; do
    mapfile -t ACLS < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$AD" 'olcAccess=*displayOrderInt*' olcAccess | awk -F': ' '/^olcAccess: /{print $2}')
    for a in "${ACLS[@]}"; do
      tmp=/tmp/del_acl.$$; cat >"$tmp" <<LDIF
dn: $AD
changetype: modify
delete: olcAccess
olcAccess: $a
LDIF
      do_modify "$tmp"
      rm -f "$tmp"
    done
  done < <(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" 'olcAccess=*displayOrderInt*' dn | awk '/^dn: /{print substr($0,5)}')
fi

# 4) スキーマの分解 → 削除
if [[ '"$DROP_SCHEMA"' == "1" ]]; then
  SCHEMA_DN="$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=schema,cn=config' '(cn=*emMailAux*)' dn | awk '/^dn: /{print substr($0,5)}')"
  if [[ -z "$SCHEMA_DN" ]]; then
    log "schema entry not found: emMailAux (already gone)"
  else
    log "schema DN: $SCHEMA_DN"

    # OC を全削除（存在しなくてもOK）
    tmp=/tmp/clear_oc.$$
    cat >"$tmp" <<LDIF
dn: $SCHEMA_DN
changetype: modify
delete: olcObjectClasses
LDIF
    do_modify "$tmp" || true
    rm -f "$tmp"

    # Attr を全削除（存在しなくてもOK）
    tmp=/tmp/clear_attr.$$
    cat >"$tmp" <<LDIF
dn: $SCHEMA_DN
changetype: modify
delete: olcAttributeTypes
LDIF
    do_modify "$tmp" || true
    rm -f "$tmp"

    # エントリ削除
    do_delete "$SCHEMA_DN" || true
  fi
fi

# 5) 検証
left_schema="$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=schema,cn=config' '(cn=*emMailAux*)' dn || true)"
left_attr="$(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" '(displayOrderInt=*)' dn || true)"
left_oc="$(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" '(objectClass=emMailAux)' dn || true)"

log "--- VERIFY ---"
echo "schema entry left?"; echo "$left_schema"
echo "DIT displayOrderInt left?"; echo "$left_attr"
echo "DIT objectClass=emMailAux left?"; echo "$left_oc"

log "=== DONE emMailAux clean ==="
EOS
)
  run_remote "$H" "$REMOTE_SCRIPT"
done

echo "All hosts processed."

