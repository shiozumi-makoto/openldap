#!/usr/bin/env bash
# ------------------------------------------------------------
# sync_domainsid_all.sh
#   指定の Domain SID を各サーバーへ設定し、結果を検証する
#   - 並列SSH実行
#   - DRY-RUN対応（--dry-run）
#   - 確認省略（-y）
#   - 確認のみ（--check-only）
# ------------------------------------------------------------
set -Eeuo pipefail

######################## 設定 ########################
# 対象サーバ一覧（必要に応じて編集）
SERVERS=(ovs-024 ovs-025 ovs-026 ovs-002 ovs-012)

# SSHユーザー
SSH_USER="root"

# 設定したい Domain SID（必須）
DOMAIN_SID="${DOMAIN_SID:-S-1-5-21-3566765955-3362818161-2431109675}"

# 接続オプション
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=5"

# 並列ジョブ数（同時に叩くサーバ数）
JOBS="${JOBS:-4}"

######################## 便利色 ########################
if test -t 1; then
  GREEN="$(tput setaf 2)"; RED="$(tput setaf 1)"; YELLOW="$(tput setaf 3)"; BLUE="$(tput setaf 4)"
  BOLD="$(tput bold)"; RESET="$(tput sgr0)"
else
  GREEN=""; RED=""; YELLOW=""; BLUE=""; BOLD=""; RESET=""
fi

######################## 引数 ########################
DRY_RUN=false
ASSUME_YES=false
CHECK_ONLY=false

usage() {
  cat <<USAGE
Usage: DOMAIN_SID=<sid> $0 [options]

Options:
  -y, --yes          確認を省略して実行
      --dry-run      実際には net setdomainsid を実行しない（確認のみ）
      --check-only   set は行わず、getdomainsid の結果だけを一覧表示
  -j N               並列数（既定: ${JOBS}）
  -l "h1 h2 ..."     サーバ名をスペース区切りで指定（SERVERSを上書き）

環境変数:
  DOMAIN_SID     設定したい Domain SID（必須）
  SSH_USER       既定: root
  JOBS           並列数 既定: ${JOBS}

例:
  DOMAIN_SID=${DOMAIN_SID} $0 --dry-run
  DOMAIN_SID=${DOMAIN_SID} $0 -y
  DOMAIN_SID=${DOMAIN_SID} $0 -l "ovs-024 ovs-025"
USAGE
}

while (( $# )); do
  case "$1" in
    -y|--yes) ASSUME_YES=true ;;
    --dry-run) DRY_RUN=true ;;
    --check-only) CHECK_ONLY=true ;;
    -j) shift; JOBS="${1:-$JOBS}" ;;
    -l) shift; IFS=' ' read -r -a SERVERS <<< "${1:-}";;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac; shift || true
done

if [[ -z "${DOMAIN_SID}" ]]; then
  echo "${RED}[ERROR] DOMAIN_SID が未指定です${RESET}"; usage; exit 1
fi

echo "${BOLD}=== Samba Domain SID 同期 ($(date '+%F %T')) ===${RESET}"
echo "ターゲットSID: ${BLUE}${DOMAIN_SID}${RESET}"
echo "対象: ${YELLOW}${SERVERS[*]}${RESET}"
echo "モード: ${YELLOW}$( $DRY_RUN && echo DRY-RUN || $CHECK_ONLY && echo CHECK-ONLY || echo APPLY )${RESET}"

if ! $ASSUME_YES; then
  read -r -p "続行しますか？ [y/N] " ans
  [[ "${ans:-N}" =~ ^[yY]$ ]] || { echo "中止しました。"; exit 1; }
fi

######################## ワーカー ########################
# サーバ1台を処理
# 出力: "host|status|localsid|domainsid|message"
work_one() {
  local host="$1"
  local msg="" rc=0

  # net コマンド存在チェック（リモート）
  if ! ssh $SSH_OPTS "${SSH_USER}@${host}" "command -v net >/dev/null 2>&1"; then
    echo "${host}|ERROR|||net コマンドなし"; return 1
  fi

  # 現在のSID取得
  local raw get_rc=0
  raw="$(ssh $SSH_OPTS "${SSH_USER}@${host}" "net getdomainsid 2>/dev/null" )" || get_rc=$?

  # 解析
  local cur_local cur_domain
  cur_local="$(awk -F': ' '/local machine/ {print $2}' <<<"$raw" | tr -d '[:space:]' || true)"
  cur_domain="$(awk -F': ' '/[dD]omain/ {print $2}' <<<"$raw" | tr -d '[:space:]' || true)"

  # check-only: 取得のみ
  if $CHECK_ONLY; then
    if [[ -n "$cur_domain" ]]; then
      echo "${host}|OK|${cur_local}|${cur_domain}|checked"
    else
      echo "${host}|WARN|${cur_local}|N/A|getdomainsid N/A"
    fi
    return 0
  fi

  # 必要なら設定
  if [[ "$cur_domain" != "$DOMAIN_SID" ]]; then
    if $DRY_RUN; then
      msg="DRY-RUN: net setdomainsid ${DOMAIN_SID}"
      rc=0
    else
      if ssh $SSH_OPTS "${SSH_USER}@${host}" "net setdomainsid ${DOMAIN_SID} >/dev/null 2>&1"; then
        msg="setdomainsid applied"
        rc=0
      else
        echo "${host}|ERROR|${cur_local}|${cur_domain:-N/A}|setdomainsid 失敗"
        return 1
      fi
    fi
    # 再取得
    raw="$(ssh $SSH_OPTS "${SSH_USER}@${host}" "net getdomainsid 2>/dev/null" )" || true
    cur_local="$(awk -F': ' '/local machine/ {print $2}' <<<"$raw" | tr -d '[:space:]' || true)"
    cur_domain="$(awk -F': ' '/[dD]omain/ {print $2}' <<<"$raw" | tr -d '[:space:]' || true)"
  else
    msg="already set"
  fi

  # 結果評価
  if [[ "$cur_domain" == "$DOMAIN_SID" ]]; then
    echo "${host}|OK|${cur_local}|${cur_domain}|${msg}"
  else
    echo "${host}|ERROR|${cur_local}|${cur_domain:-N/A}|不一致"
    return 1
  fi
}

######################## 並列実行 ########################
pids=()
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

# セマフォ（簡易ジョブ制御）
sem_count=0
for h in "${SERVERS[@]}"; do
  (
    work_one "$h" > "${tmpdir}/${h}.out" 2> "${tmpdir}/${h}.err" || echo "RC=1" > "${tmpdir}/${h}.rc"
  ) &
  pids+=( $! )
  (( ++sem_count % JOBS == 0 )) && wait -n || true
done
wait

######################## 結果表示 ########################
echo
echo "ホスト名             | Local SID                                    | Domain SID                                   | 結果"
echo "---------------------+---------------------------------------------+---------------------------------------------+------------------"

overall_rc=0
for h in "${SERVERS[@]}"; do
  if [[ -f "${tmpdir}/${h}.out" ]]; then
    IFS='|' read -r host status local domain msg < "${tmpdir}/${h}.out"
    case "$status" in
      OK)   color="$GREEN"; ;;
      WARN) color="$YELLOW"; overall_rc=1 ;;
      *)    color="$RED"; overall_rc=1 ;;
    esac
    printf "%-21s | %-43s | %-43s | %s%s%s\n" "$host" "${local:-N/A}" "${domain:-N/A}" "$color" "${status:-ERR} (${msg:-})" "$RESET"
  else
    err="$(tr -d '\r' < "${tmpdir}/${h}.err" 2>/dev/null || echo "unknown")"
    printf "%-21s | %-43s | %-43s | %sERROR (%s)%s\n" "$h" "N/A" "N/A" "$RED" "$err" "$RESET"
    overall_rc=1
  fi
done

echo "------------------------------------------------------------"
if (( overall_rc == 0 )); then
  echo "${GREEN}同期成功（全ホスト一致）${RESET}"
else
  echo "${YELLOW}一部に警告/エラーがあります。上記を確認してください。${RESET}"
fi
exit "$overall_rc"


