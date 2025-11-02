#!/usr/bin/env bash
# ------------------------------------------------------------
# check_samba_sid_all.sh
#   各サーバーの Samba SID（ローカル / ドメイン）を一覧表示する
# ------------------------------------------------------------

set -euo pipefail

# 対象サーバー一覧（必要に応じて編集）
SERVERS=(ovs-012 ovs-002 ovs-024 ovs-025 ovs-026 ovs-009 ovs-010 ovs-011)

# 実行ユーザー（root で SSHできることを前提）
SSH_USER="root"

echo "=== Samba SID 一覧確認 ($(date '+%F %T')) ==="
echo "ホスト名             | Local SID                                    | Domain SID"
echo "---------------------+---------------------------------------------+---------------------------------------------"

for host in "${SERVERS[@]}"; do
  {
    # net getlocalsid の取得
    local_sid=$(ssh -o ConnectTimeout=3 ${SSH_USER}@${host} "net getlocalsid 2>/dev/null | awk -F': ' '/SID for domain/ {print \$2}'" || echo "N/A")

    # net getdomainsid の取得
    domain_sid=$(ssh -o ConnectTimeout=3 ${SSH_USER}@${host} "net getdomainsid 2>/dev/null | awk -F': ' '/SID for local machine/ {getline; if (\$0 ~ /domain/) {print \$2}}'" || echo "N/A")

    # 表示整形
    printf "%-21s | %-43s | %-43s\n" "$host" "${local_sid:-N/A}" "${domain_sid:-N/A}"
  } &
done

wait
echo "------------------------------------------------------------"
echo "確認完了：全 ${#SERVERS[@]} 台"

