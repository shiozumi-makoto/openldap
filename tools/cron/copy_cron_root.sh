#!/usr/bin/env bash
set -eux

# === 設定 ===
SSH_USER="root"
DEST_DIR="."   # 保存先
SRC_PATH="/var/spool/cron/root"

# === 対象ホスト ===
HOSTS=(
  ovs-009
  ovs-010
  ovs-011
  ovs-024
  ovs-025
  ovs-026
  ovs-002
  ovs-012
)

# === コピー処理 ===
for host in "${HOSTS[@]}"; do
  suffix="${host##*-}"                # 例: ovs-009 → 009
  dest_file="${DEST_DIR}/root.${suffix}"
  echo "[INFO] copy ${SRC_PATH} from ${host} → ${dest_file}"
  scp "${SSH_USER}@${host}:${SRC_PATH}" "${dest_file}"
  chmod 775 ${dest_file}
done

echo "[DONE] all files copied successfully."
