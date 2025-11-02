#!/bin/bash
#--------------------------------------------------------------------
# olcAccess_sync.sh
#  - 基準ホスト（--src）の olcAccess を取得し、対象（--target）へ反映
#  - デフォルト: DRY-RUN（予定LDIF表示のみ）
#  - --confirm : 実適用（ldapmodify）
#  - --backup  : バックアップのみ取得（適用はしない）
#  - {1}/{2} などのDB番号差は suffix から自動特定
#--------------------------------------------------------------------
set -Eeuo pipefail

# ===== デフォルト設定 =====
SUFFIX="dc=e-smile,dc=ne,dc=jp"
SRC="ovs-012"
TARGETS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")
LDAPURI_ENC_LOCAL='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'   # 基準側
LDAPURI_ENC_REMOTE='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'  # 対象側
OUTDIR="/root/ldap-backup/olc-access-$(date +%F_%H%M%S)"
DRYRUN=1
BACKUP_ONLY=0
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5"
SCP_OPTS="-q"

usage() {
  cat <<'USAGE'
olcAccess_sync.sh - 基準ホストの olcAccess を対象群へ統一

使い方:
  olcAccess_sync.sh [--confirm] [--backup] [--src=HOST] [--target=h1,h2,...]
                    [--suffix="dc=..."] [--ldapi-local=URI] [--ldapi-remote=URI]
                    [--outdir=/path] [-h|--help]

モード:
  (引数なし)     : DRY-RUN（予定LDIF表示のみ）
  --confirm      : 実適用（ldapmodify実行）
  --backup       : バックアップのみ（適用しない）

主なオプション:
  --src=HOST             基準ホスト（デフォルト: ovs-012）
  --target=h1,h2,...     対象ホストCSV（デフォ: ovs-002,ovs-024,ovs-025,ovs-026）
  --suffix="dc=...,..."  対象DBの olcSuffix（デフォ: dc=e-smile,dc=ne,dc=jp）
  --ldapi-local=URI      基準側 ldapi（デフォ: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi）
  --ldapi-remote=URI     対象側 ldapi（デフォ: 同上）
  --outdir=/path         ログ出力先（デフォ: /root/ldap-apply-YYYY-MM-DD_HHMMSS）

例:
  # まずは予定だけ確認（DRY-RUN）
  olcAccess_sync.sh

  # 実適用
  olcAccess_sync.sh --confirm

  # バックアップだけ全台
  olcAccess_sync.sh --backup

  # 対象を絞って実適用（ovs-024 と 026 のみ）
  olcAccess_sync.sh --target=ovs-024,ovs-026 --confirm

  # 基準を変更（src=ovs-025）
  olcAccess_sync.sh --src=ovs-025 --confirm
USAGE
}

# ===== 引数パース =====
for arg in "$@"; do
  case "$arg" in
    --confirm) DRYRUN=0 ;;
    --backup) BACKUP_ONLY=1 ;;
    --src=*) SRC="${arg#*=}" ;;
    --target=*) IFS=',' read -r -a TARGETS <<< "${arg#*=}" ;;
    --suffix=*) SUFFIX="${arg#*=}" ;;
    --ldapi-local=*) LDAPURI_ENC_LOCAL="${arg#*=}" ;;
    --ldapi-remote=*) LDAPURI_ENC_REMOTE="${arg#*=}" ;;
    --outdir=*) OUTDIR="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[WARN] unknown arg: $arg" ;;
  esac
done

mkdir -p "$OUTDIR"

log() { echo "[$(date '+%F %T')] $*"; }

log "[INFO] START"
log "[INFO] MODE: $( ((BACKUP_ONLY)) && echo BACKUP-ONLY || ( ((DRYRUN)) && echo DRY-RUN || echo CONFIRM ))"
log "[INFO] SRC=${SRC}  TARGETS=${TARGETS[*]}"
log "[INFO] SUFFIX=${SUFFIX}"
log "[INFO] OUTDIR=${OUTDIR}"
echo

# ===== 1) 基準ホストの DN 特定 & olcAccess 取得 =====
log "[STEP] Fetch baseline olcAccess from SRC (${SRC})"
DN_LOCAL=$(ssh ${SSH_OPTS} "${SRC}" \
  "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_LOCAL}' -b cn=config \
     '(&(objectClass=olcMdbConfig)(olcSuffix=${SUFFIX}))' dn -o ldif-wrap=no 2>/dev/null \
   | awk '/^dn: /{print substr(\$0,5)}' | head -n1") || true

if [[ -z "${DN_LOCAL}" ]]; then
  log "[FATAL] Unable to find mdb DN on SRC=${SRC} (suffix=${SUFFIX})"; exit 1
fi
log "[INFO] SRC DN: ${DN_LOCAL}"

BASELINE_RAW="${OUTDIR}/baseline_raw.ldif"
BASELINE_ONLY="${OUTDIR}/baseline_olcAccess_only.ldif"
ssh ${SSH_OPTS} "${SRC}" \
  "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_LOCAL}' -b '${DN_LOCAL}' olcAccess -o ldif-wrap=no" \
  > "${BASELINE_RAW}"

grep -E '^olcAccess:' "${BASELINE_RAW}" > "${BASELINE_ONLY}" || true
if [[ ! -s "${BASELINE_ONLY}" ]]; then
  log "[FATAL] Baseline olcAccess is empty on SRC=${SRC}"; exit 1
fi

log "[INFO] Baseline lines:"
nl -ba "${BASELINE_ONLY}"
echo

# ===== 2) 対象ホストごとに処理 =====
for h in "${TARGETS[@]}"; do
  echo "== ${h} =="
  # 2-1) DN 特定
  DN_REMOTE=$(ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b cn=config \
       '(&(objectClass=olcMdbConfig)(olcSuffix=${SUFFIX}))' dn -o ldif-wrap=no 2>/dev/null \
     | awk '/^dn: /{print substr(\$0,5)}' | head -n1") || true

  if [[ -z "${DN_REMOTE}" ]]; then
    log "[ERROR] ${h}: target DN not found (suffix=${SUFFIX})"
    echo
    continue
  fi
  log "[INFO] ${h}: TARGET DN: ${DN_REMOTE}"

  # 2-2) バックアップ（常に実施）
  ssh ${SSH_OPTS} "${h}" "mkdir -p /root/ldap-backup" || true
  BK="/root/ldap-backup/olcAccess_${h}_$(date +%F_%H%M%S).ldif"
  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' '*' '+' -o ldif-wrap=no" \
    > "${OUTDIR}/$(basename "${BK}")" || true
  # ↑ ローカルにも控えを保存
  scp ${SCP_OPTS} "${OUTDIR}/$(basename "${BK}")" "${h}:${BK}" >/dev/null 2>&1 || true
  log "[INFO] ${h}: Backup saved (remote:${BK}, local:${OUTDIR}/$(basename "${BK}"))"

  # 2-3) 現状の olcAccess（ログ用）
  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
    > "${OUTDIR}/${h}_current_olcAccess.ldif" || true
  log "[INFO] ${h}: Current olcAccess captured -> ${OUTDIR}/${h}_current_olcAccess.ldif"

  # 2-4) 予定LDIF構築（ローカルで作成→リモートへ適用/表示）
  PLAN="${OUTDIR}/${h}_planned.ldif"
  {
    echo "dn: ${DN_REMOTE}"
    echo "changetype: modify"
    echo "replace: olcAccess"
    cat "${BASELINE_ONLY}"
  } > "${PLAN}"
  log "[INFO] ${h}: Planned LDIF -> ${PLAN}"

  if (( BACKUP_ONLY )); then
    log "[BACKUP-ONLY] ${h}: Skipping apply (only backup taken)"
    echo
    continue
  fi

  if (( DRYRUN )); then
    log "[DRY-RUN] ${h}: --- planned LDIF ---"
    cat "${PLAN}"
    log "[DRY-RUN] ${h}: (skip ldapmodify)"
  else
    # 配置して適用
    REMOTE_TMP="/tmp/olcAccess_planned_$$.ldif"
    scp ${SCP_OPTS} "${PLAN}" "${h}:${REMOTE_TMP}" >/dev/null
    log "[APPLY] ${h}: ldapmodify start"
    ssh ${SSH_OPTS} "${h}" "ldapmodify -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -f '${REMOTE_TMP}'"
    log "[APPLY] ${h}: done"
    ssh ${SSH_OPTS} "${h}" "rm -f '${REMOTE_TMP}'" || true
    # After
    ssh ${SSH_OPTS} "${h}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
      > "${OUTDIR}/${h}_after_olcAccess.ldif" || true
    log "[INFO] ${h}: After olcAccess -> ${OUTDIR}/${h}_after_olcAccess.ldif"
  fi

  echo
done

log "[INFO] DONE"
log "[INFO] Logs: ${OUTDIR}"

