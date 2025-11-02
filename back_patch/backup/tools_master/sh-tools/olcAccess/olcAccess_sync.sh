#!/bin/bash
#--------------------------------------------------------------------
# olcAccess_sync.sh
#
# 目的:
#  - 基準（テンプレ or SRC）から olcAccess を対象群へ反映
#  - 世代バックアップ取得、一覧表示、任意世代からのリストア
#  - ldapi を総当たりで自動検出（--ldapi-local/--ldapi-remote 指定があれば優先）
#
# モード:
#  (引数なし)        : DRY-RUN（予定LDIF表示のみ）
#  --confirm         : 実適用（ldapmodify）
#  --backup          : バックアップのみ（SRC + TARGET 全台）
#  --list            : バックアップ世代一覧
#  --restore=DIR     : 指定DIRのバックアップでリストア（olcAccessのみ）
#--------------------------------------------------------------------
set -Eeuo pipefail

# ===== デフォルト設定 =====
SUFFIX="dc=e-smile,dc=ne,dc=jp"
SRC="ovs-012"
TARGETS=("ovs-002" "ovs-024" "ovs-025" "ovs-026")

LDAPURI_ENC_LOCAL=''   # 空なら自動検出
LDAPURI_ENC_REMOTE=''  # 空なら自動検出

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
olcAccess_sync.sh - olcAccessの統一・世代バックアップ/リストア（ldapi自動検出付き）

使い方:
  olcAccess_sync.sh [--confirm] [--backup] [--list]
                    [--restore=/path/to/backup_dir]
                    [--template=/path/to/olcAccess_template.ldif]
                    [--src=HOST] [--target=h1,h2,...]
                    [--suffix="dc=..."]
                    [--ldapi-local=URI]  [--ldapi-remote=URI]
                    [--backup-root=/path] [--outdir=/path] [-h|--help]

メモ:
  - ldapi は指定が無ければ自動検出（よくあるソケットパスを総当たり）
  - DN検出は mdb/hdb/bdb すべて対応
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

ts(){ date '+%F %T'; }
log(){ echo "[$(ts)] $*"; }
uniq_hosts(){ awk '!x[$0]++'; }

# ===== ldapi 自動検出（REMOTE/LOCAL 共通のヘルパ）=====
# 引数: host
# 戻り: 使える ldapi URI（見つからなければ空）
auto_ldapi() {
  local host="$1"
  # よくある候補（順序大事）
  local -a guesses=(
    'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Fvar%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Frun%2Fldapi'
  )
  # /usr/local/openldap-*/var/run/ldapi を動的に追加（例: ovs-009）
  local dyn
  dyn=$(ssh ${SSH_OPTS} "$host" "ls -1d /usr/local/openldap-*/var/run/ldapi 2>/dev/null | head -n1") || true
  if [[ -n "$dyn" ]]; then
    local enc="${dyn//\//%2F}"
    guesses=("ldapi://$enc" "${guesses[@]}")
  fi
  # 総当たり
  local u
  for u in "${guesses[@]}"; do
    if ssh ${SSH_OPTS} "$host" "ldapsearch -LLL -Y EXTERNAL -H '$u' -b cn=config dn -o ldif-wrap=no >/dev/null 2>&1"; then
      echo "$u"; return 0
    fi
  done
  echo ""
}

# ===== 安全な DN 取得（mdb/hdb/bdb対応 + ヒント）=====
get_dn_remote() {
  local host="$1" uri="$2" suffix="$3"
  ssh ${SSH_OPTS} "$host" bash -s -- "$uri" "$suffix" <<'EOS'
LDAPURI="$1"; SUFFIX="$2"
set -Eeuo pipefail
for oclass in olcMdbConfig olcHdbConfig olcBdbConfig; do
  DN=$(ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
        "(&(objectClass=$oclass)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
      | awk "/^dn: /{print substr(\$0,5)}" | head -n1) || true
  [[ -n "$DN" ]] && { echo "$DN"; exit 0; }
done
echo "[HINT] No match for SUFFIX=$SUFFIX on $LDAPURI" 1>&2
ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
  "(objectClass=olcDatabaseConfig)" dn olcSuffix olcBackend -o ldif-wrap=no 2>/dev/null 1>&2 || true
exit 1
EOS
}

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

# ===== MODE表示 =====
if [[ -n "${RESTORE_DIR}" ]]; then MODE_STR="RESTORE"
elif (( BACKUP_ONLY )); then MODE_STR="BACKUP-ONLY"
elif (( DRYRUN )); then MODE_STR="DRY-RUN"
else MODE_STR="CONFIRM"; fi

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

# ===== ldapi URI を確定（手動指定 > 自動検出）=====
if [[ -z "$LDAPURI_ENC_LOCAL" ]]; then
  LDAPURI_ENC_LOCAL="$(auto_ldapi "$SRC")"
  [[ -z "$LDAPURI_ENC_LOCAL" ]] && { log "[FATAL] SRC(${SRC}) の ldapi が見つかりません"; exit 1; }
  log "[INFO] AUTO ldapi-local: $LDAPURI_ENC_LOCAL"
fi

if (( BACKUP_ONLY )) && [[ -z "${RESTORE_DIR}" ]]; then
  BACKUP_HOSTS="$(printf "%s\n%s\n" "${SRC}" "${TARGETS[@]}" | uniq_hosts)"
  while IFS= read -r h; do
    [[ -z "$h" ]] && continue
    echo "== ${h} =="
    # remote ldapi 決定
    local_remote="$LDAPURI_ENC_REMOTE"
    if [[ -z "$local_remote" ]]; then
      local_remote="$(auto_ldapi "$h")"
      [[ -z "$local_remote" ]] && { log "[ERROR] ${h}: ldapi が見つからずスキップ"; echo; continue; }
      log "[INFO] ${h}: AUTO ldapi-remote: $local_remote"
    fi
    DN_REMOTE="$(get_dn_remote "$h" "$local_remote" "$SUFFIX")" || true
    if [[ -z "${DN_REMOTE}" ]]; then
      log "[ERROR] ${h}: target DN not found (suffix=${SUFFIX})"
      echo; continue
    fi
    log "[INFO] ${h}: TARGET DN: ${DN_REMOTE}"

    ssh ${SSH_OPTS} "${h}" "mkdir -p '${BACKUP_ROOT}'" || true
    BK_REMOTE="${BACKUP_ROOT}/olcAccess_${h}_$(date +%F_%H%M%S).ldif"
    BK_LOCAL="${OUTDIR}/$(basename "${BK_REMOTE}")"
    ssh ${SSH_OPTS} "${h}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${local_remote}' -b '${DN_REMOTE}' '*' '+' -o ldif-wrap=no" \
      > "${BK_LOCAL}" || true
    scp ${SCP_OPTS} "${BK_LOCAL}" "${h}:${BK_REMOTE}" >/dev/null 2>&1 || true
    log "[INFO] ${h}: Backup saved (remote:${BK_REMOTE}, local:${BK_LOCAL})"
    echo
  done <<< "${BACKUP_HOSTS}"
  log "[INFO] DONE"; log "[INFO] Logs: ${OUTDIR}"; exit 0
fi

# ===== 基準 olcAccess の準備（RESTORE以外）=====
BASELINE_ONLY=""
if [[ -z "${RESTORE_DIR}" ]]; then
  if [[ -n "${TEMPLATE_FILE}" ]]; then
    [[ -f "${TEMPLATE_FILE}" ]] || { log "[FATAL] template not found: ${TEMPLATE_FILE}"; exit 1; }
    BASELINE_ONLY="${OUTDIR}/baseline_from_template.ldif"
    grep -E '^[[:space:]]*olcAccess:' "${TEMPLATE_FILE}" | sed 's/^[[:space:]]*//' > "${BASELINE_ONLY}" || true
    [[ -s "${BASELINE_ONLY}" ]] || { log "[FATAL] no olcAccess lines in template"; exit 1; }
    log "[INFO] Baseline from template:"; nl -ba "${BASELINE_ONLY}"; echo
  else
    log "[STEP] Fetch baseline olcAccess from SRC (${SRC})"
    DN_LOCAL="$(get_dn_remote "$SRC" "$LDAPURI_ENC_LOCAL" "$SUFFIX")" || true
    [[ -n "${DN_LOCAL}" ]] || { log "[FATAL] cannot find mdb DN on SRC=${SRC}"; exit 1; }
    log "[INFO] SRC DN: ${DN_LOCAL}"
    BASELINE_RAW="${OUTDIR}/baseline_raw.ldif"
    BASELINE_ONLY="${OUTDIR}/baseline_olcAccess_only.ldif"
    ssh ${SSH_OPTS} "${SRC}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${LDAPURI_ENC_LOCAL}' -b '${DN_LOCAL}' olcAccess -o ldif-wrap=no" \
      > "${BASELINE_RAW}"
    grep -E '^olcAccess:' "${BASELINE_RAW}" > "${BASELINE_ONLY}" || true
    [[ -s "${BASELINE_ONLY}" ]] || { log "[FATAL] Baseline empty on SRC=${SRC}"; exit 1; }
    log "[INFO] Baseline lines:"; nl -ba "${BASELINE_ONLY}"; echo
  fi
fi

# ===== 対象ホスト処理 =====
for h in "${TARGETS[@]}"; do
  echo "== ${h} =="
  # remote ldapi 決定
  local_remote="$LDAPURI_ENC_REMOTE"
  if [[ -z "$local_remote" ]]; then
    local_remote="$(auto_ldapi "$h")"
    [[ -z "$local_remote" ]] && { log "[ERROR] ${h}: ldapi が見つからずスキップ"; echo; continue; }
    log "[INFO] ${h}: AUTO ldapi-remote: $local_remote"
  fi

  DN_REMOTE="$(get_dn_remote "$h" "$local_remote" "$SUFFIX")" || true
  if [[ -z "${DN_REMOTE}" ]]; then
    log "[ERROR] ${h}: target DN not found (suffix=${SUFFIX})"
    echo; continue
  fi
  log "[INFO] ${h}: TARGET DN: ${DN_REMOTE}"

  # バックアップ
  ssh ${SSH_OPTS} "${h}" "mkdir -p '${BACKUP_ROOT}'" || true
  BK_REMOTE="${BACKUP_ROOT}/olcAccess_${h}_$(date +%F_%H%M%S).ldif"
  BK_LOCAL="${OUTDIR}/$(basename "${BK_REMOTE}")"
  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${local_remote}' -b '${DN_REMOTE}' '*' '+' -o ldif-wrap=no" \
    > "${BK_LOCAL}" || true
  scp ${SCP_OPTS} "${BK_LOCAL}" "${h}:${BK_REMOTE}" >/dev/null 2>&1 || true
  log "[INFO] ${h}: Backup saved (remote:${BK_REMOTE}, local:${BK_LOCAL})"

  # 現状（ログ用）
  ssh ${SSH_OPTS} "${h}" \
    "ldapsearch -LLL -Y EXTERNAL -H '${local_remote}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
    > "${OUTDIR}/${h}_current_olcAccess.ldif" || true
  log "[INFO] ${h}: Current olcAccess -> ${OUTDIR}/${h}_current_olcAccess.ldif"

  # 予定LDIF
  PLAN="${OUTDIR}/${h}_planned.ldif"
  if [[ -n "${RESTORE_DIR}" ]]; then
    [[ -d "${RESTORE_DIR}" ]] || { log "[ERROR] ${h}: restore dir not found: ${RESTORE_DIR}"; echo; continue; }
    mapfile -t cand < <(ls -1 "${RESTORE_DIR}/olcAccess_${h}_"*.ldif 2>/dev/null || true)
    (( ${#cand[@]} > 0 )) || { log "[ERROR] ${h}: no backup file in restore dir"; echo; continue; }
    RESTORE_FILE="${cand[0]}"
    RESTORE_ONLY="${OUTDIR}/${h}_restore_olcAccess_only.ldif"
    grep -E '^olcAccess:' "${RESTORE_FILE}" > "${RESTORE_ONLY}" || true
    [[ -s "${RESTORE_ONLY}" ]] || { log "[ERROR] ${h}: restore file has no olcAccess lines"; echo; continue; }
    { echo "dn: ${DN_REMOTE}"; echo "changetype: modify"; echo "replace: olcAccess"; cat "${RESTORE_ONLY}"; } > "${PLAN}"
  else
    { echo "dn: ${DN_REMOTE}"; echo "changetype: modify"; echo "replace: olcAccess"; cat "${BASELINE_ONLY}"; } > "${PLAN}"
  fi
  log "[INFO] ${h}: Planned LDIF -> ${PLAN}"

  if (( DRYRUN )); then
    log "[DRY-RUN] ${h}: --- planned LDIF ---"; cat "${PLAN}"; log "[DRY-RUN] ${h}: (skip ldapmodify)"
  else
    REMOTE_TMP="/tmp/olcAccess_planned_$$.ldif"
    scp ${SCP_OPTS} "${PLAN}" "${h}:${REMOTE_TMP}" >/dev/null
    log "[APPLY] ${h}: ldapmodify start"
    ssh ${SSH_OPTS} "${h}" "ldapmodify -Y EXTERNAL -H '${local_remote}' -f '${REMOTE_TMP}'"
    log "[APPLY] ${h}: done"
    ssh ${SSH_OPTS} "${h}" "rm -f '${REMOTE_TMP}'" || true
    ssh ${SSH_OPTS} "${h}" \
      "ldapsearch -LLL -Y EXTERNAL -H '${local_remote}' -b '${DN_REMOTE}' olcAccess -o ldif-wrap=no" \
      > "${OUTDIR}/${h}_after_olcAccess.ldif" || true
    log "[INFO] ${h}: After olcAccess -> ${OUTDIR}/${h}_after_olcAccess.ldif"
  fi
  echo
done

log "[INFO] DONE"
log "[INFO] Logs: ${OUTDIR}"


