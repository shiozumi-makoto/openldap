#!/usr/bin/env bash
set -Eeuo pipefail

# === 設定（必要なら書き換え） ==========================================
SSH_USER="${SSH_USER:-root}"
SRC_DIR="${SRC_DIR:-/usr/local/etc/openldap/tools/smb}"

HOST_002="${HOST_002:-ovs-002}"
HOST_012="${HOST_012:-ovs-012}"
HOST_024="${HOST_024:-ovs-024}"
HOST_025="${HOST_025:-ovs-025}"
HOST_026="${HOST_026:-ovs-026}"

REMOTE_CONF="/etc/samba/smb.conf"
REMOTE_TMP="/etc/samba/smb.conf.tmp"

FILE_002="${FILE_002:-smb.conf.002}"
FILE_012="${FILE_012:-smb.conf.012}"
FILE_024="${FILE_024:-smb.conf.024}"
FILE_025="${FILE_025:-smb.conf.025}"
FILE_026="${FILE_026:-smb.conf.026}"

# =====================================================================

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }

deploy_one(){
  local host="$1" file="$2" suffix="$3"

  log "=== ${host}: 配布開始 (${file}) ==="
  if [[ ! -f "${SRC_DIR}/${file}" ]]; then
    echo "ERROR: ${SRC_DIR}/${file} が見つかりません" >&2
    exit 1
  fi

  # 1) 既存 smb.conf をホストサフィックス＆日時でバックアップ
  ssh "${SSH_USER}@${host}" bash -s <<'EOS' || { echo "SSH失敗"; exit 1; }
set -Eeuo pipefail
REMOTE_CONF="/etc/samba/smb.conf"
TS="$(date +%Y%m%d_%H%M%S)"
if [[ -f "$REMOTE_CONF" ]]; then
  cp -a "$REMOTE_CONF" "${REMOTE_CONF}.${TS}"
fi
EOS

  # suffix 付きバックアップ（.002 / .012）
  ssh "${SSH_USER}@${host}" "if [[ -f '${REMOTE_CONF}' ]]; then cp -a '${REMOTE_CONF}' '${REMOTE_CONF}.${suffix}'; fi"

  # 2) 転送（.tmp で投下）
  scp -p "${SRC_DIR}/${file}" "${SSH_USER}@${host}:${REMOTE_TMP}"

  # 3) 権限整備→本番配置→構文チェック→サービス再起動
  ssh "${SSH_USER}@${host}" bash -s <<'EOS'
set -Eeuo pipefail
REMOTE_CONF="/etc/samba/smb.conf"
REMOTE_TMP="/etc/samba/smb.conf.tmp"

chown root:root "${REMOTE_TMP}"
chmod 0644 "${REMOTE_TMP}"
mv -f "${REMOTE_TMP}" "${REMOTE_CONF}"

# 構文チェック（失敗時は直前バックアップで戻すのが安全だが、ここではエラーで止める）
# testparm -s >/dev/null

# systemd のユニット名は環境差があるので存在していれば再起動
systemctl daemon-reload || true
for SVC in smb smbd; do
  systemctl is-active --quiet "$SVC" && systemctl restart "$SVC" && echo "restarted: $SVC" || true
done
for SVC in nmb nmbd; do
  systemctl is-active --quiet "$SVC" && systemctl restart "$SVC" && echo "restarted: $SVC" || true
done
EOS

  log "=== ${host}: 配布完了 ==="
}

main(){
  log "配布元: ${SRC_DIR}"
  log "ターゲット: ${HOST_002}(${FILE_002}), ${HOST_012}(${FILE_012})"

#  deploy_one "${HOST_002}" "${FILE_002}" "002"
#  deploy_one "${HOST_012}" "${FILE_012}" "012"
deploy_one "${HOST_024}" "${FILE_024}" "024"


  log "すべて完了"
}

main "$@"

