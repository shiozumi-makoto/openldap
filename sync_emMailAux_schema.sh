#!/usr/bin/env bash
# sync_emMailAux_schema.sh (no read -d '', quoting-safe)
# - 全ホスト: バックアップ → emMailAux を replace（未存在は add）→ ハッシュ検証
set -Eeuo pipefail

# ===== 設定 =====
DEFAULT_HOSTS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")
if [[ -n "${HOSTS:-}" ]]; then
  read -r -a HOSTS_ARR <<<"$HOSTS"
else
  HOSTS_ARR=("${DEFAULT_HOSTS[@]}")
fi

die(){ echo "ERROR: $*" >&2; exit 1; }
need(){ command -v "$1" >/dev/null 2>&1 || die "need cmd: $1"; }
need ssh; need scp; need awk; need sed; need mktemp; need date

mk_ldif_replace_local(){
  # 引数: DN, 出力パス
  local dn="$1" tmp="$2"
  cat >"$tmp" <<'LDIF'
dn: __DN__
changetype: modify
replace: olcAttributeTypes
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC 'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Display order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrderingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.2 NAME 'displayNameOrder' DESC 'Sort key for display name' EQUALITY caseIgnoreMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.15 )
-
replace: olcObjectClasses
olcObjectClasses: ( 1.3.6.1.4.1.55555.2.1 NAME 'emMailAux' DESC 'Aux class to hold alternate emails and display order' SUP top AUXILIARY MAY ( mailAlternateAddress $ displayNameOrder $ displayOrderInt ) )
LDIF
  # DN だけ安全置換
  local dn_esc
  dn_esc="$(printf '%s' "$dn" | sed -e 's/[&/|]/\\&/g')"
  sed -i -e "s|__DN__|$dn_esc|g" "$tmp"
}

mk_ldif_add_local(){
  # 引数: 出力パス
  local tmp="$1"
  cat >"$tmp" <<'LDIF'
dn: cn=emMailAux,cn=schema,cn=config
objectClass: olcSchemaConfig
cn: emMailAux
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.1 NAME 'mailAlternateAddress' DESC 'Additional email addresses for a person' EQUALITY caseIgnoreIA5Match SUBSTR caseIgnoreIA5SubstringsMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.26 )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.4 NAME 'displayOrderInt' DESC 'Display order integer (for sorting)' EQUALITY integerMatch ORDERING integerOrderingMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.27 SINGLE-VALUE )
olcAttributeTypes: ( 1.3.6.1.4.1.55555.1.2 NAME 'displayNameOrder' DESC 'Sort key for display name' EQUALITY caseIgnoreMatch SYNTAX 1.3.6.1.4.1.1466.115.121.1.15 )
olcObjectClasses: ( 1.3.6.1.4.1.55555.2.1 NAME 'emMailAux' DESC 'Aux class to hold alternate emails and display order' SUP top AUXILIARY MAY ( mailAlternateAddress $ displayNameOrder $ displayOrderInt ) )
LDIF
}

for h in "${HOSTS_ARR[@]}"; do
  echo "==== [$h] START ===="

  # 1) DNの取得（{n}対応）
  DN="$(ssh root@"$h" \
    "ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config '(cn=*emMailAux*)' dn | awk '/^dn:/{sub(/^dn: /,\"\");print;exit}'" || true)"
  echo "[$h] detected DN: ${DN:-<none>}"

  # 2) バックアップ
  ssh root@"$h" bash -s <<'REMOTE_BAK'
set -Eeuo pipefail
TS=$(date +%Y%m%d%H%M%S)
mkdir -p /root/ldap-schema-bak
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config > /root/ldap-schema-bak/schema_all.$TS.ldif
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=config '(cn=*emMailAux*)' > /root/ldap-schema-bak/emMailAux.$TS.ldif || true
echo "Backup: /root/ldap-schema-bak/schema_all.$TS.ldif"
REMOTE_BAK

  # 3) LDIF をローカル生成 → scp → リモート適用
  if [[ -n "$DN" ]]; then
    echo "[$h] emMailAux exists -> REPLACE"
    tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
    mk_ldif_replace_local "$DN" "$tmp"
    scp -q "$tmp" "root@${h}:/root/emMailAux_replace.ldif"
    ssh root@"$h" "ldapmodify -Y EXTERNAL -H ldapi:/// -f /root/emMailAux_replace.ldif"
    rm -f "$tmp"; trap - EXIT
  else
    echo "[$h] emMailAux missing -> ADD"
    tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
    mk_ldif_add_local "$tmp"
    scp -q "$tmp" "root@${h}:/root/emMailAux_add.ldif"
    ssh root@"$h" "ldapadd -Y EXTERNAL -H ldapi:/// -f /root/emMailAux_add.ldif"
    rm -f "$tmp"; trap - EXIT
  fi

  # 4) 検証（ハッシュ表示）
  ssh root@"$h" bash -s <<'REMOTE_VER'
set -Eeuo pipefail
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b 'cn=emMailAux,cn=schema,cn=config' \
  olcAttributeTypes olcObjectClasses -o ldif-wrap=no \
| awk -F': ' '/^(olcAttributeTypes|olcObjectClasses): /{print $2}' \
| sha256sum | awk '{print "schema_hash="$1}'
REMOTE_VER

  echo "==== [$h] DONE ===="
done

echo "All done."

