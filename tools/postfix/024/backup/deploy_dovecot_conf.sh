#!/usr/bin/env bash
set -Eeuo pipefail

# ==== 設定 ====
SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-024}"
REMOTE_FILE="/etc/dovecot/dovecot.conf"
REMOTE_DIR="/etc/dovecot"
REMOTE_BAK_DIR="/var/backups/dovecot"
LOCAL_FILE="${LOCAL_FILE:-./dovecot.conf}"
RESTART="${RESTART:-1}"

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

main(){
  need ssh
  need scp

  [[ -f "${LOCAL_FILE}" ]] || die "ローカルの ${LOCAL_FILE} が見つかりません"

  TS="$(date +%Y%m%d_%H%M%S)"
  log "=== バックアップ作成先: ${REMOTE_BAK_DIR}/${TS}"

  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS'
set -Eeuo pipefail
REMOTE_DIR="/etc/dovecot"
REMOTE_BAK_DIR="/var/backups/dovecot"
TS="$(date +%Y%m%d_%H%M%S)"
install -d -m 0755 "${REMOTE_BAK_DIR}/${TS}"
if [[ -f "${REMOTE_DIR}/dovecot.conf" ]]; then
  cp -a "${REMOTE_DIR}/dovecot.conf" "${REMOTE_BAK_DIR}/${TS}/dovecot.conf"
fi
EOS

  log "=== 転送: ${LOCAL_FILE} -> ${SSH_USER}@${HOST}:${REMOTE_FILE}.tmp"
  scp -p "${LOCAL_FILE}" "${SSH_USER}@${HOST}:${REMOTE_FILE}.tmp"

  log "=== 反映 & パーミッション設定 & 検証"
  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS'
set -Eeuo pipefail
REMOTE_FILE="/etc/dovecot/dovecot.conf"
# 反映
chown root:root "${REMOTE_FILE}.tmp"
chmod 0644      "${REMOTE_FILE}.tmp"
mv -f "${REMOTE_FILE}.tmp" "${REMOTE_FILE}"

# TLS ファイルの存在チェック（警告のみ）
warn(){ echo "WARN: $*" >&2; }

[[ -r /etc/postfix/tls/postfix.crt ]] || warn "/etc/postfix/tls/postfix.crt が読めません"
[[ -r /etc/postfix/tls/postfix.key ]] || warn "/etc/postfix/tls/postfix.key が読めません"
[[ -r /etc/postfix/tls/ca.crt      ]] || warn "/etc/postfix/tls/ca.crt が読めません"

# SELinux 環境なら念のため
command -v restorecon >/dev/null 2>&1 && restorecon -v /etc/dovecot/dovecot.conf || true

# 構文チェック
if ! dovecot -n >/dev/null; then
  echo "dovecot -n が失敗しました。バックアップから戻すことを検討してください。" >&2
  exit 2
fi

# 再起動
systemctl restart dovecot
systemctl enable dovecot >/dev/null 2>&1 || true

# ポート確認（失敗しても致命ではない）
ss -lntup | egrep ':(143|993|110|995)\b' || true

echo "Dovecot 反映完了"
EOS

  log "=== 完了"
}

main "$@"
