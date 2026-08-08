#!/usr/bin/env bash
set -Eeuo pipefail

SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-009}"

EXT_DIR="${EXT_DIR:-009}"
EXT="${EXT:-009}"

BASE_DIR="${BASE_DIR:-/usr/local/etc/openldap/tools/postfix}"
SRC_DIR="${SRC_DIR:-${BASE_DIR}/${EXT_DIR}}"

FILES=(
  "main.cf"
  "master.cf"
  "ldap-alias.cf"
  "ldap-users.cf"
  "virtual-regexp"
  "transport"
  "virtual"
  "sasl_passwd"
  "tls_policy"
)

POSTMAP_FILES=(
  "transport"
  "virtual"
  "sasl_passwd"
  "tls_policy"
)

REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"

log(){ printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need_cmd(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

cleanup_tmp(){
  ssh -o BatchMode=yes \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${HOST}" \
      "rm -f '${REMOTE_DIR}'/*.tmp" >/dev/null 2>&1 || true
}

main(){
  need_cmd scp
  need_cmd ssh
  need_cmd sha256sum
  trap 'cleanup_tmp' EXIT

  [[ -d "${SRC_DIR}" ]] || die "SRC_DIRが存在しません: ${SRC_DIR}"

  log "=== 配布元     : ${SRC_DIR}"
  log "=== 配布先     : ${SSH_USER}@${HOST}:${REMOTE_DIR}"
  log "=== 対象       : ${FILES[*]}"
  log "=== postmap対象: ${POSTMAP_FILES[*]}"
  log "=== EXT_DIR    : ${EXT_DIR}"
  log "=== EXT(suffix): ${EXT:-<none>}"

  # ----------------------------------------------------------
  # 配布元ファイルの決定
  #   foo.009 があれば優先し、なければ foo を使用
  # ----------------------------------------------------------
  declare -A SRC_PATHS=()
  declare -A SRC_SHA=()

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

    SRC_SHA["$f"]="$(sha256sum "${SRC_PATHS[$f]}" | awk '{print $1}')"
  done

  log "=== ${HOST}: SSH/Postfix 事前チェック"
  ssh -o BatchMode=yes \
      -o ConnectTimeout=10 \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${HOST}" \
      'command -v postfix >/dev/null 2>&1 || { echo "postfix が見つかりません"; exit 11; }; command -v postmap >/dev/null 2>&1 || { echo "postmap が見つかりません"; exit 12; }' \
      || die "リモートの事前チェックに失敗しました"

  TS="$(date +%Y%m%d_%H%M%S)"
  BAK_DIR="${REMOTE_BAK_DIR}/${TS}"

  log "=== ${HOST}: バックアップ準備（${BAK_DIR}）"

  # 現在の設定ファイルと、存在する .db を退避
  ssh -o BatchMode=yes \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${HOST}" bash -s -- \
      "${REMOTE_DIR}" "${REMOTE_BAK_DIR}" "${TS}" "${FILES[@]}" <<'EOS' \
      || die "SSH接続に失敗（バックアップ準備）"
set -Eeuo pipefail

REMOTE_DIR="$1"
REMOTE_BAK_DIR="$2"
TS="$3"
shift 3
FILES=("$@")

BAK_DIR="${REMOTE_BAK_DIR}/${TS}"
install -d -m 0700 "${BAK_DIR}"

for f in "${FILES[@]}"; do
  if [[ -f "${REMOTE_DIR}/${f}" ]]; then
    cp -a "${REMOTE_DIR}/${f}" "${BAK_DIR}/${f}"
  fi
  if [[ -f "${REMOTE_DIR}/${f}.db" ]]; then
    cp -a "${REMOTE_DIR}/${f}.db" "${BAK_DIR}/${f}.db"
  fi
done
EOS

  log "=== ${HOST}: 転送（.tmp）"
  for f in "${FILES[@]}"; do
    src="${SRC_PATHS[$f]}"
    log "    -> ${f} : $(basename "${src}")"
    scp -q -p \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=accept-new \
        "${src}" \
        "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}.tmp"
  done

  log "=== ${HOST}: 反映・postmap・postfix check・reload"

  ssh -o BatchMode=yes \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${HOST}" bash -s -- \
      "${REMOTE_DIR}" "${BAK_DIR}" \
      "${#FILES[@]}" "${FILES[@]}" \
      "${#POSTMAP_FILES[@]}" "${POSTMAP_FILES[@]}" <<'EOS'
set -Eeuo pipefail

REMOTE_DIR="$1"
BAK_DIR="$2"
FILE_COUNT="$3"
shift 3

FILES=("${@:1:${FILE_COUNT}}")
shift "${FILE_COUNT}"

POSTMAP_COUNT="$1"
shift
POSTMAP_FILES=("${@:1:${POSTMAP_COUNT}}")

rollback(){
  echo "ERROR: 反映処理に失敗しました。ロールバックします..." >&2

  for f in "${FILES[@]}"; do
    if [[ -f "${BAK_DIR}/${f}" ]]; then
      cp -a "${BAK_DIR}/${f}" "${REMOTE_DIR}/${f}"
    else
      rm -f "${REMOTE_DIR}/${f}"
    fi

    if [[ -f "${BAK_DIR}/${f}.db" ]]; then
      cp -a "${BAK_DIR}/${f}.db" "${REMOTE_DIR}/${f}.db"
    else
      rm -f "${REMOTE_DIR}/${f}.db"
    fi
  done

  postfix check || true
  systemctl reload postfix 2>/dev/null || postfix reload 2>/dev/null || true
}

trap 'rc=$?; if [[ $rc -ne 0 ]]; then rollback; fi; exit $rc' EXIT

# すべての tmp が揃っていることを確認
for f in "${FILES[@]}"; do
  [[ -f "${REMOTE_DIR}/${f}.tmp" ]] || {
    echo "${REMOTE_DIR}/${f}.tmp がありません" >&2
    exit 20
  }
done

# 本番ファイルへ反映
for f in "${FILES[@]}"; do
  chown root:root "${REMOTE_DIR}/${f}.tmp"
  chmod 0644 "${REMOTE_DIR}/${f}.tmp"

  if [[ "${f}" == "sasl_passwd" ]]; then
    chmod 0600 "${REMOTE_DIR}/${f}.tmp"
  fi

  mv -f "${REMOTE_DIR}/${f}.tmp" "${REMOTE_DIR}/${f}"
done

# hash DB 再生成
for f in "${POSTMAP_FILES[@]}"; do
  if [[ -f "${REMOTE_DIR}/${f}" ]]; then
    postmap "${REMOTE_DIR}/${f}"
  fi
done

# Postfix 設定検証
postfix check

# 設定再読込
if systemctl reload postfix 2>/dev/null; then
  :
elif postfix reload 2>/dev/null; then
  :
elif systemctl restart postfix 2>/dev/null; then
  :
else
  echo "Postfix の reload/restart に失敗しました" >&2
  exit 30
fi

trap - EXIT
echo "反映完了（BACKUP=${BAK_DIR}）"
EOS

  # ----------------------------------------------------------
  # 配布後の SHA256 一致確認
  # ----------------------------------------------------------
  log "=== ${HOST}: 配布後の一致確認"

  VERIFY_OK=0
  VERIFY_NG=0

  echo
  echo "============================================================"
  echo " Postfix deploy 結果サマリー"
  echo "============================================================"

  for f in "${FILES[@]}"; do
    remote_sha="$(
      ssh -o BatchMode=yes \
          -o StrictHostKeyChecking=accept-new \
          "${SSH_USER}@${HOST}" \
          "sha256sum '${REMOTE_DIR}/${f}'" | awk '{print $1}'
    )"

    if [[ "${SRC_SHA[$f]}" == "${remote_sha}" ]]; then
      printf '%-20s : OK SAME\n' "${f}"
      VERIFY_OK=$((VERIFY_OK + 1))
    else
      printf '%-20s : !! DIFFERENT\n' "${f}"
      VERIFY_NG=$((VERIFY_NG + 1))
    fi
  done

  echo "------------------------------------------------------------"
  printf '%-14s : %d\n' "OK" "${VERIFY_OK}"
  printf '%-14s : %d\n' "DIFFERENT" "${VERIFY_NG}"
  echo "------------------------------------------------------------"

  if [[ "${VERIFY_NG}" -eq 0 ]]; then
    echo "RESULT: ★ すべての Postfix 設定ファイルを正常に配布しました ★"
  else
    echo "RESULT: !! ${VERIFY_NG} ファイルが配布元と一致していません"
    exit 40
  fi

  echo "BACKUP: ${BAK_DIR}"
  echo "============================================================"

  log "=== すべて完了（${HOST} / EXT=${EXT}）"
}

main "$@"
