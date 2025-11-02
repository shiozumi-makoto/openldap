#!/usr/bin/env bash
# clean_emMailAux_all_parallel.sh
set -Eeuo pipefail

SSH_USER="${SSH_USER:-root}"
HOSTS="${HOSTS:-ovs-002 ovs-024 ovs-025 ovs-026}"
BASE_DN="${BASE_DN:-dc=e-smile,dc=ne,dc=jp}"
DRY_RUN="${DRY_RUN:-0}"

REMOTE_PAYLOAD='
set -Eeuo pipefail
: "${BASE_DN:?BASE_DN not set}"

log(){ printf "[%s] %s\n" "$(date +%F\ %T)" "$*"; }
ok(){ log "OK: $*"; }
warn(){ log "WARN: $*"; }

log "=== START emMailAux clean (parallel) ==="
log "BaseDN: ${BASE_DN}"

# sanity
ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" -s base dn >/dev/null

# DIT refs（念のため掃除：ゼロが期待値）
mapfile -t DNs_attr < <(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" "(displayOrderInt=*)" dn | awk "/^dn: /{print substr(\$0,5)}")
mapfile -t DNs_oc   < <(ldapsearch -LLL -x -H ldapi:/// -b "$BASE_DN" "(objectClass=emMailAux)" dn | awk "/^dn: /{print substr(\$0,5)}")

if [[ ${#DNs_attr[@]} -gt 0 ]]; then
  tmp=/tmp/del_displayOrderInt.$$; : > "$tmp"
  for dn in "${DNs_attr[@]}"; do
    printf "dn: %s\nchangetype: modify\ndelete: displayOrderInt\n\n" "$dn" >> "$tmp"
  done
  ldapmodify -Y EXTERNAL -H ldapi:/// -f "$tmp" || warn "del displayOrderInt failed"
  rm -f "$tmp"
fi

if [[ ${#DNs_oc[@]} -gt 0 ]]; then
  tmp=/tmp/del_emMailAux_oc.$$; : > "$tmp"
  for dn in "${DNs_oc[@]}"; do
    printf "dn: %s\nchangetype: modify\ndelete: objectClass\nobjectClass: emMailAux\n\n" "$dn" >> "$tmp"
  done
  ldapmodify -Y EXTERNAL -H ldapi:/// -f "$tmp" || warn "del emMailAux OC failed"
  rm -f "$tmp"
fi

# schema DN
SCHEMA_DN="$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=schema,cn=config" "(cn=*emMailAux*)" dn | awk "/^dn: /{print substr(\$0,5)}")"
if [[ -z "$SCHEMA_DN" ]]; then
  ok "schema already gone"
  exit 0
fi
log "schema DN: $SCHEMA_DN"

# OC 全削除
cat >/tmp/clear_oc.ldif <<LDIF
dn: $SCHEMA_DN
changetype: modify
delete: olcObjectClasses
LDIF
ldapmodify -Y EXTERNAL -H ldapi:/// -f /tmp/clear_oc.ldif || true

# Attr 全削除
cat >/tmp/clear_attr.ldif <<LDIF
dn: $SCHEMA_DN
changetype: modify
delete: olcAttributeTypes
LDIF
ldapmodify -Y EXTERNAL -H ldapi:/// -f /tmp/clear_attr.ldif || true

# エントリ削除
ldapdelete -Y EXTERNAL -H ldapi:/// "$SCHEMA_DN" || true

# 検証
left="$(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=schema,cn=config" "(cn=*emMailAux*)" dn || true)"
if [[ -z "$left" ]]; then
  ok "schema removed"
else
  warn "schema still present:"
  echo "$left"
fi

log "=== DONE emMailAux clean (parallel) ==="
'

run_host(){
  local H="$1"
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "---- [DRY] $H ----"
    echo "BASE_DN=$BASE_DN"
    echo "$REMOTE_PAYLOAD"
  else
    echo "---- [$H] run ----"
    ssh -o StrictHostKeyChecking=no -l "$SSH_USER" "$H" "BASE_DN='$BASE_DN'" 'bash -s' <<<"$REMOTE_PAYLOAD"
  fi
}

# 並列実行（& + wait）
pids=()
for H in $HOSTS; do
  run_host "$H" &
  pids+=($!)
done

# 全ノード終了待ち
rc=0
for pid in "${pids[@]}"; do
  wait "$pid" || rc=$?
done

echo "All hosts processed. rc=$rc"
exit "$rc"

