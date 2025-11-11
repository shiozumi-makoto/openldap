#!/usr/bin/env bash
set -Eeuo pipefail

# ================== 設定（環境変数/引数で上書き可） ==================
SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-009}"

# ディレクトリ切替（例: 024 → /postfix/024 を参照）
EXT_DIR="${EXT_DIR:-009}"

# ファイル拡張子切替（例: 009 → main.cf.009 を優先）
EXT="${EXT:-}"

# ローカル配置元
BASE_DIR="${BASE_DIR:-/usr/local/etc/openldap/tools/postfix}"
SRC_DIR="${SRC_DIR:-${BASE_DIR}/${EXT_DIR}}"

# 転送対象ファイル
# --- 例: 配布対象の拡張 ---
FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp" \
       "transport" "virtual" "sasl_passwd")
# FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp")

# リモート配置先
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"
# =====================================================================

usage(){
  cat <<USAGE
Usage: HOST=ovs-009 EXT=009 EXT_DIR=009 bash $0
  ENV/ARGS:
    HOST         デプロイ先ホスト（例: ovs-009）
    SSH_USER     SSHユーザ（default: root）
    EXT_DIR      ローカルのサブディレクトリ（default: 024）
    EXT          配布するローカルファイルの拡張子（例: 009）
                 -> "main.cf.009" が存在する場合それを優先、なければ "main.cf"
    BASE_DIR     ベースディレクトリ（default: ${BASE_DIR}）
    SRC_DIR      ソースディレクトリ（default: ${SRC_DIR}）
USAGE
}

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need_cmd(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

cleanup_tmp(){
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" \
    "rm -f ${REMOTE_DIR}/*.tmp" >/dev/null 2>&1 || true
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

  # 1) ローカル存在チェック（.EXT を優先、無ければ無印）
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

  # 2) postfix存在確認
  log "=== ${HOST}: 事前チェック（postfix存在確認）"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" \
    'command -v postfix >/dev/null 2>&1 || { echo "postfix が見つかりません"; exit 11; }' \
    || die "リモートに postfix がありません"

  # 3) バックアップ準備
  TS="$(date +%Y%m%d_%H%M%S)"
  log "=== ${HOST}: バックアップ準備（${REMOTE_BAK_DIR}/${TS}）"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" bash -s <<EOS || die "SSH接続に失敗（バックアップ準備）"
set -Eeuo pipefail
REMOTE_DIR="${REMOTE_DIR}"
REMOTE_BAK_DIR="${REMOTE_BAK_DIR}"
TS="${TS}"
install -d -m 2775 "\${REMOTE_BAK_DIR}/\${TS}"
for f in ${FILES[*]}; do
  if [[ -f "\${REMOTE_DIR}/\${f}" ]]; then
    cp -a "\${REMOTE_DIR}/\${f}" "\${REMOTE_BAK_DIR}/\${TS}/\${f}"
  fi
done
EOS

  # 4) 転送（.tmp）
  log "=== ${HOST}: 転送（.tmp）"
  for f in "${FILES[@]}"; do
    src="${SRC_PATHS[$f]}"
    log "    -> ${f} : $(basename "${src}")"
    scp -p -o StrictHostKeyChecking=accept-new \
      "${src}" "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}.tmp"
  done



  # 5) 権限・反映・検証
  log "=== ${HOST}: 反映 & チェック"
  ssh -o StrictHostKeyChecking=accept-new "${SSH_USER}@${HOST}" bash -s <<'EOS' || exit 1
set -Eeuo pipefail
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"
LATEST_BAK="$(ls -1dt "${REMOTE_BAK_DIR}"/[0-9]* 2>/dev/null | head -n1 || true)"
[[ -n "${LATEST_BAK}" ]] || { echo "バックアップディレクトリが見つかりません"; exit 1; }

FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp")

for f in "${FILES[@]}"; do
  [[ -f "${REMOTE_DIR}/${f}.tmp" ]] || { echo "${REMOTE_DIR}/${f}.tmp がありません"; exit 1; }
  chown root:root "${REMOTE_DIR}/${f}.tmp"
  chmod 0644      "${REMOTE_DIR}/${f}.tmp"
  mv -f "${REMOTE_DIR}/${f}.tmp" "${REMOTE_DIR}/${f}"
done

if ! postfix check; then
  echo "postfix check が失敗。ロールバックします..."
  for f in "${FILES[@]}"; do
    if [[ -f "${LATEST_BAK}/${f}" ]]; then
      cp -a "${LATEST_BAK}/${f}" "${REMOTE_DIR}/${f}"
    fi
  done
  postfix check || true
  exit 2
fi

systemctl reload postfix 2>/dev/null || postfix reload 2>/dev/null || systemctl restart postfix 2>/dev/null || true
echo "反映完了（LATEST_BAK=${LATEST_BAK}）"
EOS

  log "=== すべて完了（${HOST} / EXT=${EXT}）"
}

# 簡易オプション（--help だけ）
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then usage; exit 0; fi

main "$@"
