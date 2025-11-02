#!/bin/bash
#--------------------------------------------------------------------
# olcAccess_sync.sh
#
# 目的:
#  - 基準（テンプレートファイル or SRCホスト）から olcAccess を取得して対象群へ反映
#  - 世代付きバックアップの取得、一覧表示、任意世代からのリストア
#
# モード:
#  (引数なし)        : DRY-RUN（予定LDIF表示のみ）
#  --confirm         : 実適用（ldapmodify）
#  --backup          : バックアップのみ取得（SRC + TARGET 全台）
#  --list            : バックアップ・ルート配下の世代フォルダ一覧を表示
#  --restore=<DIR>   : 指定DIRのバックアップからリストア（対象ホストへ）
#
# 優先順位:
#  --template=<FILE> があればそれを適用。なければ --src=<HOST> から live 取得。
#  DB番号 {1}/{2} は olcSuffix で自動特定。
#--------------------------------------------------------------------
set -Eeuo pipefail

# ===== デフォルト設定 =====
SUFFIX="dc=e-smile,dc=ne,dc=jp"
SRC="ovs-012"
TARGETS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")

LDAPURI_ENC_LOCAL='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
LDAPURI_ENC_REMOTE='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'

BACKUP_ROOT="/root/ldap-backup"
OUTDIR="${BACKUP_ROOT}/olc-access-$(date +%F_%H%M%S)"

DRYRUN=1
BACKUP_ONLY=0
DO_LIST=0
RESTORE_DIR=""
TEMPLATE_FILE=""

SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=7"
SCP_OPTS="-q"

usage() {
  cat <<'USAGE'
olcAccess_sync.sh - 基準テンプレート or SRC の olcAccess を対象群へ統一し、世代バックアップ/リストアを行う

使い方:
  olcAccess_sync.sh [--confirm] [--backup] [--list]
                    [--restore=/path/to/backup_dir]
                    [--template=/path/to/olcAccess_template.ldif]
                    [--src=HOST] [--target=h1,h2,...]
                    [--suffix="dc=..."] [--ldapi-local=URI] [--ldapi-remote=URI]
                    [--backup-root=/path] [--outdir=/path] [-h|--help]

モード:
  (引数なし)        : DRY-RUN（予定LDIF表示のみ）
  --confirm         : 実適用（ldapmodify）
  --backup          : バックアップのみ（SRC + TARGET 全台）
  --list            : バックアップ世代一覧
  --restore=DIR     : 指定DIRのバックアップで各ターゲットをリストア（olcAccessのみ）

基準の決め方:
  --template=FILE   : FILE の "olcAccess:" 行を基準に使用（最優先）
  --src=HOST        : 上記が無い場合、SRC の cn=config から live 取得

バックアップ:
  --backup-root=DIR : 世代親ディレクトリ（デフォ: /root/ldap-backup）
  --outdir=DIR      : 今回の世代フォルダ（省略時は ${BACKUP_ROOT}/olc-access-YYYY-MM-DD_HHMMSS）
USAGE
}

# ===== 引数パース =====
for arg in "$@"; do
  case "$arg" in
    --confirm) DRYRUN=0 ;;
    --backup) BACKUP_ONLY=1 ;;
    --list) DO_LIST=1 ;;
    --restore=*) RESTORE_DIR="${arg#*=}" ;;
    --template=*) TEMPLATE_FILE="${arg#*=}" ;;
    --src=*) SRC="${arg#*=}" ;;
    --target=*) IFS=',' read -r -a TARGETS <<< "${arg#*=}" ;;
    --suffix=*) SUFFIX="${arg#*=}" ;;
    --ldapi-local=*) LDAPURI_ENC_LOCAL="${arg#*=}" ;;
    --ldapi-remote=*) LDAPURI_ENC_REMOTE="${arg#*=}" ;;
    --backup-root=*) BACKUP_ROOT="${arg#*=}" ;;
    --outdir=*) OUTDIR="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[WARN] unknown arg: $arg" ;;
  esac
done

log() { echo "[$(date '+%F %T')] $*"; }

# ===== --list: 世代一覧 =====
if (( DO_LIST )); then
  echo "[LIST] backup root: ${BACKUP_ROOT}"
  if [[ -d "${BACKUP_ROOT}" ]]; then
    find "${BACKUP_ROOT}" -maxdepth 1 -type d -name 'olc-access-*' -printf '%P\n' | sort
  else
    echo "(no backup root yet)"
  fi
  exit 0
fi

mkdir -p "${OUTDIR}"

# ===== ユーティリティ =====
uniq_hosts() { awk '!x[$0]++'; }

# 安全な DN 取得（ssh + heredoc）
get_dn_remote() {
  local host="$1" uri="$2" suffix="$3"
  ssh ${SSH_OPTS} "$host" bash -s -- "$uri" "$suffix" <<'EOS'
LDAPURI="$1"; SUFFIX="$2"
ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
  "(&(objectClass=olcMdbConfig)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
| awk '/^dn: /{print substr($0,5)}' | head -n1
EOS
}

# ===== START ログ =====
MODE_STR=""
if [[ -n "${RESTORE_DIR}" ]]; then
  MODE_STR="RESTORE"
elif (( BACKUP_ONLY )); then
  MODE_STR="BACKUP-ONLY"
elif (( DRYRUN )); then
  MODE_STR="DRY-RUN"
else
  MODE_STR="CONFIRM"
fi

log "[INFO] START"
log "[INFO] MODE         : ${MODE_STR}"
log "[INFO] SRC          : ${SRC}"
log "[INFO] TARGETS      : ${TARGETS[*]}"
log "[INFO] SUFFIX       : ${SUFFIX}"
log "[INFO] BACKUP_ROOT  : ${BACKUP_ROOT}"
log "[INFO] OUTDIR       : ${OUTDIR}"
[[ -n "${TEMPLATE_FILE}" ]] && log "[INFO] TEMPLATE     : ${TEMPLATE_FILE}"
[[ -n "${RESTORE_DIR}"  ]] && log "[INFO] RESTORE_DIR  : ${RESTORE_DIR}"
echo

# ===== --backup モード（SRC+TARGET 全台）=====
if (( BACKUP_ONLY )) && [[ -z "${RESTORE_DIR}" ]]; then
  BACKUP_HOSTS="$(printf "%s\n%s\n" "${SRC}" "${TARGETS[@]}" | uniq_hosts)"
  while IFS= read -r h; do
    [[ -z "$h" ]] && continue
    echo "== ${h} =="
    DN_REMOTE="$(get_dn_remote "$h" "$LDAPURI_ENC_REMOTE" "$SUFFIX")" || true
    if [[ -z "${DN_REMOTE}" ]]; then
      log "[ERROR] ${h}: target DN not found (suffix=${SUFFIX})"
      echo; continue
    fi
    log "[INFO] ${h}: TARGET DN: ${DN_REMOTE}"

    ssh ${SSH_OPTS} "${h}" "mkdir -p '${BACKUP_ROOT}'" || true
    BK_REMOTE="${BACKUP_ROOT}/olcAccess_${h}_$(date +%F_%H%M%S).ldif"
    BK_LOCAL="${OUTDIR}/$(basename "${BK_REMOTE}")"
    ssh ${SSH_OPTS} "${h}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' '*' '+' -o ldif-wrap=no" \
      > "${BK_LOCAL}" || true
    scp ${SCP_OPTS} "${BK_LOCAL}" "${h}:${BK_REMOTE}" >/dev/null 2>&1 || true
    log "[INFO] ${h}: Backup saved (remote:${BK_REMOTE}, local:${BK_LOCAL})"
    echo
  done <<< "${BACKUP_HOSTS}"
  log "[INFO] DONE"
  log "[INFO] Logs: ${OUTDIR}"
  exit 0
fi

# ===== 基準 olcAccess の準備（RESTORE以外の時）=====
BASELINE_ONLY=""
if [[ -z "${RESTORE_DIR}" ]]; then
  if [[ -n "${TEMPLATE_FILE}" ]]; then
    if [[ ! -f "${TEMPLATE_FILE}" ]]; then
      log "[FATAL] template file not found: ${TEMPLATE_FILE}"; exit 1
    fi
    BASELINE_ONLY="${OUTDIR}/baseline_from_template.ldif"
    grep -E '^[[:space:]]*olcAccess:' "${TEMPLATE_FILE}" | sed 's/^[[:space:]]*//' > "${BASELINE_ONLY}" || true
    if [[ ! -s "${BASELINE_ONLY}" ]]; then
      log "[FATAL] no 'olcAccess:' lines in template: ${TEMPLATE_FILE}"; exit 1
    fi
    log "[INFO] Baseline from template:"; nl -ba "${BASELINE_ONLY}"; echo
  else
    log "[STEP] Fetch baseline olcAccess from SRC (${SRC})"
    DN_LOCAL="$(get_dn_remote "$SRC" "$LDAPURI_ENC_LOCAL" "$SUFFIX")" || true
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
    log "[INFO] Baseline lines:"; nl -ba "${BASELINE_ONLY}"; echo
  fi
fi

# ===== 対象ホストごとに処理（RESTORE or 通常適用）=====
for h in "${TARGETS[@]}"; do
  echo "== ${h} =="
  DN_REMOTE="$(get_dn_remote "$h" "$LDAPURI_ENC_REMOTE" "$SUFFIX")" || true
  if [[ -z "${DN_REMOTE}" ]]; then
    log "[ERROR] ${h}: target DN not found (suffix=${SUFFIX})"
    echo; continue
  fi
  log "[INFO] ${h}: TARGET DN: ${DN_REMOTE}"

  ssh ${SSH_OPTS} "${h}" "mkdir -p '${BACKUP_ROOT}'" || true
  BK_REMOTE="${BACKUP_ROOT}/olcAccess_${h}_$(date +%F_%H%M%S).ldif"
  BK_LOCAL="${OUTDIR}/$(basename "${BK_REMOTE}")"
  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' '*' '+' -o ldif-wrap=no" \
    > "${BK_LOCAL}" || true
  scp ${SCP_OPTS} "${BK_LOCAL}" "${h}:${BK_REMOTE}" >/dev/null 2>&1 || true
  log "[INFO] ${h}: Backup saved (remote:${BK_REMOTE}, local:${BK_LOCAL})"

  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
    > "${OUTDIR}/${h}_current_olcAccess.ldif" || true
  log "[INFO] ${h}: Current olcAccess -> ${OUTDIR}/${h}_current_olcAccess.ldif"

  PLAN="${OUTDIR}/${h}_planned.ldif"
  if [[ -n "${RESTORE_DIR}" ]]; then
    if [[ ! -d "${RESTORE_DIR}" ]]; then
      log "[ERROR] ${h}: restore dir not found: ${RESTORE_DIR}"
      echo; continue
    fi
    mapfile -t cand < <(ls -1 "${RESTORE_DIR}/olcAccess_${h}_"*.ldif 2>/dev/null || true)
    if (( ${#cand[@]} == 0 )); then
      log "[ERROR] ${h}: no backup file for host in: ${RESTORE_DIR}"
      echo; continue
    fi
    RESTORE_FILE="${cand[0]}"
    log "[INFO] ${h}: Restore source: ${RESTORE_FILE}"

    RESTORE_ONLY="${OUTDIR}/${h}_restore_olcAccess_only.ldif"
    grep -E '^olcAccess:' "${RESTORE_FILE}" > "${RESTORE_ONLY}" || true
    if [[ ! -s "${RESTORE_ONLY}" ]]; then
      log "[ERROR] ${h}: restore file has no 'olcAccess:' lines"; echo; continue
    fi
    { echo "dn: ${DN_REMOTE}"; echo "changetype: modify"; echo "replace: olcAccess"; cat "${RESTORE_ONLY}"; } > "${PLAN}"
  else
    { echo "dn: ${DN_REMOTE}"; echo "changetype: modify"; echo "replace: olcAccess"; cat "${BASELINE_ONLY}"; } > "${PLAN}"
  fi

  log "[INFO] ${h}: Planned LDIF -> ${PLAN}"

  if (( DRYRUN )); then
    log "[DRY-RUN] ${h}: --- planned LDIF ---"
    cat "${PLAN}"
    log "[DRY-RUN] ${h}: (skip ldapmodify)"
  else
    REMOTE_TMP="/tmp/olcAccess_planned_$$.ldif"
    scp ${SCP_OPTS} "${PLAN}" "${h}:${REMOTE_TMP}" >/dev/null
    log "[APPLY] ${h}: ldapmodify start"
    ssh ${SSH_OPTS} "${h}" "ldapmodify -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -f '${REMOTE_TMP}'"
    log "[APPLY] ${h}: done"
    ssh ${SSH_OPTS} "${h}" "rm -f '${REMOTE_TMP}'" || true
    ssh ${SSH_OPTS} "${h}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_REMOTE}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
      > "${OUTDIR}/${h}_after_olcAccess.ldif" || true
    log "[INFO] ${h}: After olcAccess -> ${OUTDIR}/${h}_after_olcAccess.ldif"
  fi
  echo
done

log "[INFO] DONE"
log "[INFO] Logs: ${OUTDIR}"


