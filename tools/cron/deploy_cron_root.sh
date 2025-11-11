#!/usr/bin/env bash
set -Eeuo pipefail

# ==========================================================
# deploy_cron_root.sh
#   コピー済みの root.cron ファイルを各ホストへデプロイ
#
#   使用例:
#     ./deploy_cron_root.sh --host=ovs-009,ovs-010
#
# ==========================================================

SSH_USER="root"
SRC_DIR="."              # ファイルのあるローカルディレクトリ
REMOTE_PATH="/var/spool/cron/root"
PERM="600"               # 推奨パーミッション（cron用）

usage() {
  cat <<'USAGE'
Usage:
  deploy_cron_root.sh --host=ovs-009,ovs-010,...
Options:
  --host    対象ホストをカンマ区切りで指定（必須）
Example:
  ./deploy_cron_root.sh --host=ovs-009,ovs-010,ovs-011
USAGE
  exit 1
}

# === 引数解析 ===
HOSTS_STR=""
for arg in "$@"; do
  case "$arg" in
    --host=*)
      HOSTS_STR="${arg#*=}"
      ;;
    -h|--help)
      usage
      ;;
  esac
done

if [[ -z "${HOSTS_STR}" ]]; then
  echo "[ERROR] --host= を指定してください。" >&2
  usage
fi

IFS=',' read -r -a HOSTS <<< "${HOSTS_STR}"

# === デプロイ処理 ===
for host in "${HOSTS[@]}"; do
  suffix="${host##*-}"                         # 例: ovs-009 → 009
  src_file="${SRC_DIR}/root.${suffix}"

  if [[ ! -f "${src_file}" ]]; then
    echo "[WARN] ${src_file} が存在しません。スキップします。"
    continue
  fi

  echo "[INFO] deploying ${src_file} → ${host}:${REMOTE_PATH}"
  scp "${src_file}" "${SSH_USER}@${host}:${REMOTE_PATH}.tmp"
  ssh "${SSH_USER}@${host}" "mv ${REMOTE_PATH}.tmp ${REMOTE_PATH} && chmod ${PERM} ${REMOTE_PATH}"
  echo "[OK] ${host} へデプロイ完了"
done

echo "[DONE] 全ての指定ホストへのデプロイが完了しました。"
