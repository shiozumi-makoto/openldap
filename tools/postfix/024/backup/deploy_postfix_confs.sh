#!/usr/bin/env bash
set -Eeuo pipefail

# ================== 設定（必要なら上書き可能な環境変数） ==================
SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-024}"

# ローカル（このスクリプトを実行するマシン）での配置元
SRC_DIR="${SRC_DIR:-/usr/local/etc/openldap/tools/postfix/024}"

# 転送対象ファイル
FILES=("main.cf" "master.cf" "ldap-alias.cf" "ldap-users.cf" "virtual-regexp")

# リモート（ovs-024）側の配置先
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"

# =====================================================================

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }

need_cmd(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

main(){
  need_cmd scp
  need_cmd ssh

  log "=== 配布元: ${SRC_DIR}"
  log "=== 配布先: ${SSH_USER}@${HOST}:${REMOTE_DIR}"
  log "=== 対象   : ${FILES[*]}"

  # 1) ローカルにファイルがあるかチェック
  for f in "${FILES[@]}"; do
    [[ -f "${SRC_DIR}/${f}" ]] || die "${SRC_DIR}/${f} が見つかりません"
  done

  # 2) リモートでバックアップ準備
  TS="$(date +%Y%m%d_%H%M%S)"
  log "=== ${HOST}: バックアップ準備（${REMOTE_BAK_DIR}/${TS}）"
  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS' || die "SSH接続に失敗しました（バックアップ準備）"
set -Eeuo pipefail
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"
TS="$(date +%Y%m%d_%H%M%S)"

install -d -m 2775 "${REMOTE_BAK_DIR}/${TS}"
# 対象ファイルをバックアップ
for f in main.cf master.cf ldap-alias.cf ldap-users.cf virtual-regexp; do
  if [[ -f "${REMOTE_DIR}/${f}" ]]; then
    cp -a "${REMOTE_DIR}/${f}" "${REMOTE_BAK_DIR}/${TS}/${f}"
  fi
done
EOS

  # 3) 転送（.tmp）
  log "=== ${HOST}: 転送（.tmp）"
  for f in "${FILES[@]}"; do
    scp -p "${SRC_DIR}/${f}" "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}.tmp"
  done

  # 4) 権限整備→本番反映→postfix check → reload
  log "=== ${HOST}: 反映 & チェック"
  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS' || exit 1
set -Eeuo pipefail
REMOTE_DIR="/etc/postfix"
REMOTE_BAK_DIR="/var/backups/postfix"
LATEST_BAK="$(ls -1dt "${REMOTE_BAK_DIR}"/[0-9]* 2>/dev/null | head -n1 || true)"
[[ -n "${LATEST_BAK}" ]] || { echo "バックアップディレクトリが見つかりません"; exit 1; }

for f in main.cf master.cf ldap-alias.cf ldap-users.cf virtual-regexp; do
  [[ -f "${REMOTE_DIR}/${f}.tmp" ]] || { echo "${REMOTE_DIR}/${f}.tmp がありません"; exit 1; }
  chown root:root "${REMOTE_DIR}/${f}.tmp"
  chmod 0644      "${REMOTE_DIR}/${f}.tmp"
  mv -f "${REMOTE_DIR}/${f}.tmp" "${REMOTE_DIR}/${f}"
done

if ! postfix check; then
  echo "postfix check が失敗。ロールバックします..."
  for f in main.cf master.cf ldap-alias.cf ldap-users.cf virtual-regexp; do
    if [[ -f "${LATEST_BAK}/${f}" ]]; then
      cp -a "${LATEST_BAK}/${f}" "${REMOTE_DIR}/${f}"
    fi
  done
  postfix check || true
  exit 2
fi

systemctl reload postfix 2>/dev/null || postfix reload 2>/dev/null || systemctl restart postfix 2>/dev/null || true

echo "反映完了"
EOS
  rc=$?
  if [[ $rc -ne 0 ]]; then
    die "デプロイ失敗（rc=${rc}）"
  fi

  log "=== すべて完了（${HOST}）"
}

main "$@"
