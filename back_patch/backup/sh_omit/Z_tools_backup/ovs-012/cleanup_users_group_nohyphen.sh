#!/usr/bin/env bash
set -euo pipefail

# === 設定 ===
LDAP_HOST="ldap://192.168.61.12"
BASE_DN="dc=e-smile,dc=ne,dc=jp"
BIND_DN="cn=Admin,dc=e-smile,dc=ne,dc=jp"
BIND_PW='es0356525566'

GROUP_DN="cn=users,ou=Groups,${BASE_DN}"
PEOPLE_OU="ou=People,${BASE_DN}"

# DRY_RUN=1 なら実行せず表示のみ
DRY_RUN="${DRY_RUN:-1}"

WORKDIR="/root/ldap_cleanup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$WORKDIR"
LDIF_DEL_GROUP="$WORKDIR/del_memberuid_nohyphen.ldif"
LIST_NOHYPHEN="$WORKDIR/nohyphen_uids.txt"
LOG="$WORKDIR/run.log"

echo "# workdir: $WORKDIR"
echo "# log    : $LOG"

# === 1) cn=users の memberUid を収集（ハイフンなしだけ抽出） ===
echo "[*] Collecting memberUid without hyphen from ${GROUP_DN} ..." | tee -a "$LOG"
ldapsearch -x -H "$LDAP_HOST" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$GROUP_DN" '(objectClass=posixGroup)' memberUid \
| awk '/^memberUid: /{print $2}' \
| grep -E '^[A-Za-z0-9][A-Za-z0-9_.]*$' \
| grep -v -- '-' \
| sort -u > "$LIST_NOHYPHEN"

COUNT="$(wc -l < "$LIST_NOHYPHEN" || true)"
echo "[*] Found ${COUNT} uid(s) without hyphen" | tee -a "$LOG"
if [[ "$COUNT" -eq 0 ]]; then
  echo "[*] Nothing to do." | tee -a "$LOG"
  exit 0
fi

echo "[*] List: $LIST_NOHYPHEN" | tee -a "$LOG"

# === 2) グループからの削除 LDIF 生成 ===
echo "[*] Generating LDIF to delete those memberUid from ${GROUP_DN} ..." | tee -a "$LOG"
{
  echo "dn: ${GROUP_DN}"
  echo "changetype: modify"
  while read -r u; do
    [[ -z "$u" ]] && continue
    echo "delete: memberUid"
    echo "memberUid: ${u}"
    echo "-"
  done < "$LIST_NOHYPHEN"
} > "$LDIF_DEL_GROUP"

echo "[*] LDIF: $LDIF_DEL_GROUP" | tee -a "$LOG"

# === 3) 適用（DRY-RUNか本番か） ===
if [[ "${DRY_RUN}" = "1" ]]; then
  echo "[DRY-RUN] Would run:"
  echo "ldapmodify -x -H \"$LDAP_HOST\" -D \"$BIND_DN\" -w '***' -f \"$LDIF_DEL_GROUP\""
else
  echo "[*] Applying group modifications ..." | tee -a "$LOG"
  ldapmodify -x -H "$LDAP_HOST" -D "$BIND_DN" -w "$BIND_PW" -f "$LDIF_DEL_GROUP" | tee -a "$LOG"
fi

# === 4) アカウント削除（存在確認してから） ===
#    ※ 完全削除。退避させたい場合は ldapmodrdn で Disabled OU へ移動に変更してください。
echo "[*] Deleting LDAP accounts (uid=...,${PEOPLE_OU}) ..." | tee -a "$LOG"
while read -r u; do
  [[ -z "$u" ]] && continue
  DN="uid=${u},${PEOPLE_OU}"

  # 存在確認
  if ldapsearch -x -H "$LDAP_HOST" -D "$BIND_DN" -w "$BIND_PW" -b "$DN" -s base dn >/dev/null 2>&1; then
    if [[ "${DRY_RUN}" = "1" ]]; then
      echo "[DRY-RUN] Would delete: ${DN}"
    else
      echo "[*] ldapdelete ${DN}" | tee -a "$LOG"
      ldapdelete -x -H "$LDAP_HOST" -D "$BIND_DN" -w "$BIND_PW" "$DN" | tee -a "$LOG"
    fi
  else
    echo "[i] Not found (skip): ${DN}" | tee -a "$LOG"
  fi
done < "$LIST_NOHYPHEN"

echo "[*] Done. See $LOG"

