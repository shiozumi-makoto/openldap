
#!/usr/bin/env bash
set -Eeuo pipefail

SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-009}"

EXT_DIR="${EXT_DIR:-009}"
EXT="${EXT:-009}"

BASE_DIR="${BASE_DIR:-/usr/local/etc/openldap/tools/postfix}"
SRC_DIR="${SRC_DIR:-${BASE_DIR}/${EXT_DIR}}"
FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp" "transport" "virtual" "sasl_passwd")

REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"

log(){ printf '[%s] %s
' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need_cmd(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

cleanup_tmp(){
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}"     "rm -f ${REMOTE_DIR}/*.tmp" >/dev/null 2>&1 || true
}

main(){
  need_cmd scp
  need_cmd ssh
  trap 'cleanup_tmp' EXIT

  [[ -d "${SRC_DIR}" ]] || die "SRC_DIRが存在しません: ${SRC_DIR}"

  log "=== 配布元     : ${SRC_DIR}"
  log "=== 配布先     : ${SSH_USER}@${HOST}:${REMOTE_DIR}"
  log "=== 対象       : ${FILES[*]}"
  log "=== EXT_DIR    : ${EXT_DIR}"
  log "=== EXT(suffix): ${EXT:-<none>}"

  declare -A SRC_PATHS=()
  for f in "${FILES[@]}"; do
    cand_with="${SRC_DIR}/${f}${EXT:+.${EXT}}"
    cand_plain="${SRC_DIR}/${f}"
    if [[ -n "${EXT}" && -f "${cand_with}" ]]; then
      SRC_PATHS["$f"]="${cand_with}"
    elif [[ -f "${cand_plain}" ]]; then
      SRC_PATHS["$f"]="${cand_plain}"
    else
      die "必要ファイルが見つかりません: ${cand_with} も ${cand_plain} も無し"
    fi
  done

  log "=== ${HOST}: 事前チェック（postfix存在確認）"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}"     'command -v postfix >/dev/null 2>&1 || { echo "postfix が見つかりません"; exit 11; }'     || die "リモートに postfix がありません"

  TS="$(date +%Y%m%d_%H%M%S)"
  log "=== ${HOST}: バックアップ準備（${REMOTE_BAK_DIR}/${TS}）"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" bash -s <<EOS || die "SSH接続に失敗（バックアップ準備）"
set -Eeuo pipefail
REMOTE_DIR="${REMOTE_DIR}"
REMOTE_BAK_DIR="${REMOTE_BAK_DIR}"
TS="${TS}"
install -d -m 2775 "${REMOTE_BAK_DIR}/${TS}"
for f in main.cf master.cf ldap-alias.cf ldap-users.cf virtual-regexp transport virtual sasl_passwd; do
  if [[ -f "${REMOTE_DIR}/${f}" ]]; then
    cp -a "${REMOTE_DIR}/${f}" "${REMOTE_BAK_DIR}/${TS}/${f}"
  fi
done
EOS

  log "=== ${HOST}: 転送（.tmp）"
  for f in "${FILES[@]}"; do
    src="${SRC_PATHS[$f]}"
    log "    -> ${f} : $(basename "${src}")"
    scp -p -o StrictHostKeyChecking=accept-new       "${src}" "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}.tmp"
  done

  log "=== ${HOST}: 反映 & チェック"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" bash -s <<'EOS' || exit 1
set -Eeuo pipefail
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"
LATEST_BAK="$(ls -1dt "${REMOTE_BAK_DIR}"/[0-9]* 2>/dev/null | head -n1 || true)"
[[ -n "${LATEST_BAK}" ]] || { echo "バックアップディレクトリが見つかりません"; exit 1; }

FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp" "transport" "virtual" "sasl_passwd")

for f in "${FILES[@]}"; do
  [[ -f "${REMOTE_DIR}/${f}.tmp" ]] || { echo "${REMOTE_DIR}/${f}.tmp がありません"; exit 1; }
  chown root:root "${REMOTE_DIR}/${f}.tmp"
  chmod 0644      "${REMOTE_DIR}/${f}.tmp"
  if [[ "${f}" == "sasl_passwd" ]]; then chmod 0600 "${REMOTE_DIR}/${f}.tmp"; fi
  mv -f "${REMOTE_DIR}/${f}.tmp" "${REMOTE_DIR}/${f}"
done

for f in transport virtual sasl_passwd; do
  if [[ -f "${REMOTE_DIR}/${f}" ]]; then
    postmap "${REMOTE_DIR}/${f}" || { echo "postmap失敗: ${f}"; exit 2; }
  fi
done

if ! postfix check; then
  echo "postfix check が失敗。ロールバックします..."
  for f in "${FILES[@]}"; do
    if [[ -f "${LATEST_BAK}/${f}" ]]; then
      cp -a "${LATEST_BAK}/${f}" "${REMOTE_DIR}/${f}"
    fi
  done
  postfix check || true
  exit 3
fi

systemctl reload postfix 2>/dev/null || postfix reload 2>/dev/null || systemctl restart postfix 2>/dev/null || true
echo "反映完了（LATEST_BAK=${LATEST_BAK}）"
EOS

  log "=== すべて完了（${HOST} / EXT=${EXT}）"
}

main "$@"
