#!/usr/bin/env bash
set -Eeuo pipefail

# ============================================================
# set_sessionlog_all.sh
#   OpenLDAP syncprov の olcSpSessionlog を一括変更
#   既定: 1000、--confirm で実行、それ以外は確認のみ
# ============================================================

# ---- 設定（必要なら上書き可能） ----------------------------
SSH_USER="${SSH_USER:-root}"
# 既定ホスト（必要に応じて並び替え・追加OK）
HOSTS_DEF=("ovs-002" "ovs-012" "ovs-024" "ovs-025" "ovs-026")
SESSIONLOG_DEFAULT=1000

# ---- 色／ログ ------------------------------------------------
if [[ -t 1 ]]; then
  C_G="\e[32m"; C_Y="\e[33m"; C_R="\e[31m"; C_C="\e[36m"; C_B="\e[34m"; C_N="\e[0m"
else
  C_G=""; C_Y=""; C_R=""; C_C=""; C_B=""; C_N=""
fi
ts(){ date +'%F %T'; }
log(){ printf "[%s] %b%s%b\n" "$(ts)" "${2:-$C_C}" "$1" "$C_N"; }
ok(){  log "$1" "$C_G"; }
warn(){ log "$1" "$C_Y"; }
err(){  log "$1" "$C_R"; }

need_cmd(){ command -v "$1" >/dev/null 2>&1 || { err "コマンドが見つかりません: $1"; exit 127; }; }

# ---- 使い方 --------------------------------------------------
usage(){
  cat <<'USAGE'
Usage:
  set_sessionlog_all.sh [--confirm] [--hosts ovs-002,ovs-012,...] [--value N]

Options:
  --confirm           実際に変更を適用します（未指定時は確認のみ）
  --hosts LIST        対象ホスト（カンマ区切り）。省略時は既定ホスト
  --value N           設定値（デフォ1000）
  -h, --help          このヘルプ

環境変数:
  SSH_USER            SSH ログインユーザー（既定: root）

挙動:
  * 各ホストで ldapi:/// に対して EXTERNAL で ldapsearch/ldapmodify を実行します
  * syncprov オーバーレイの DN を自動検出し、olcSpSessionlog を置換します
  * 変更前後の値を表示して検証します
USAGE
}

# ---- 引数解析 ------------------------------------------------
CONFIRM=false
HOSTS=("${HOSTS_DEF[@]}")
SESSIONLOG="$SESSIONLOG_DEFAULT"

while (($#)); do
  case "$1" in
    --confirm) CONFIRM=true;;
    --hosts)
      shift
      IFS=',' read -r -a HOSTS <<<"${1:-}"
      ;;
    --value)
      shift
      SESSIONLOG="${1:-$SESSIONLOG_DEFAULT}"
      ;;
    -h|--help) usage; exit 0;;
    *) err "不明な引数: $1"; usage; exit 2;;
  esac
  shift || true
done

# ---- 事前チェック --------------------------------------------
need_cmd ssh
need_cmd awk
need_cmd sed

log "対象ホスト: ${HOSTS[*]}"
log "設定値     : ${SESSIONLOG}"
$CONFIRM || warn "ドライラン（確認のみ）。変更するには --confirm を付けてください。"

# ---- リモートで実行するスクリプト（Here-Doc） ----------------
read -r -d '' REMOTE_SCRIPT <<'EOS' || true
set -Eeuo pipefail

C_G=""; C_Y=""; C_R=""; C_C=""; C_N=""
if [[ -t 1 ]]; then
  C_G="\e[32m"; C_Y="\e[33m"; C_R="\e[31m"; C_C="\e[36m"; C_N="\e[0m"
fi
ts(){ date +'%F %T'; }
log(){ printf "[%s] %b%s%b\n" "$(ts)" "${2:-$C_C}" "$1" "$C_N"; }
ok(){  log "$1" "$C_G"; }
warn(){ log "$1" "$C_Y"; }
err(){  log "$1" "$C_R"; }

need_cmd(){ command -v "$1" >/dev/null 2>&1 || { err "コマンドが見つかりません: $1"; exit 127; }; }

VALUE="${VALUE:?missing VALUE}"
DO_APPLY="${DO_APPLY:?missing DO_APPLY}"

need_cmd ldapsearch
need_cmd ldapmodify

# syncprov オーバーレイの DN を自動検出（olcDatabase={X}mdb を含むものを優先）
DN=$(
  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b cn=config '(olcOverlay=syncprov)' dn 2>/dev/null \
  | awk '/^dn: /{print $2}' \
  | awk '/olcDatabase=\{[0-9]+\}mdb/{print; found=1} END{if(!found) exit 0}'
)

if [[ -z "${DN:-}" ]]; then
  # フォールバック：一般的な {1}mdb or {2}mdb を順にチェック
  for idx in 1 2 0; do
    try="olcOverlay={0}syncprov,olcDatabase={${idx}}mdb,cn=config"
    if ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$try" dn >/dev/null 2>&1; then
      DN="$try"; break
    fi
  done
fi

if [[ -z "${DN:-}" ]]; then
  err "syncprov の DN を特定できませんでした。"
  exit 1
fi

log "検出した DN: ${DN}"

# 現在値の取得
CUR=$(
  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DN" olcSpSessionlog 2>/dev/null \
  | awk '/^olcSpSessionlog:/{print $2; exit}'
)
[[ -z "${CUR:-}" ]] && CUR="(未設定)"
log "現在の olcSpSessionlog: ${CUR}"

if [[ "$DO_APPLY" != "1" ]]; then
  warn "ドライラン: 変更しません → 設定予定値: ${VALUE}"
  exit 0
fi

if [[ "$CUR" == "$VALUE" ]]; then
  ok "すでに ${VALUE} です。変更不要。"
  exit 0
fi

# 変更 LDIF を即時適用
LDIF=$(cat <<LD
dn: ${DN}
changetype: modify
replace: olcSpSessionlog
olcSpSessionlog: ${VALUE}
LD
)

printf "%s\n" "$LDIF" \
| ldapmodify -Y EXTERNAL -H ldapi:/// >/tmp/.set_sessionlog_apply.log 2>&1 \
&& ok "olcSpSessionlog を ${VALUE} に更新しました。" \
|| { err "ldapmodify 失敗。/tmp/.set_sessionlog_apply.log を確認してください。"; exit 1; }

# 検証
NEW=$(
  ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$DN" olcSpSessionlog 2>/dev/null \
  | awk '/^olcSpSessionlog:/{print $2; exit}'
)
if [[ "$NEW" == "$VALUE" ]]; then
  ok "検証OK: ${NEW}"
else
  err "検証NG: 期待=${VALUE}, 実際=${NEW:-空}"
  exit 1
fi
EOS

# ---- 実行ループ ----------------------------------------------
rc_all=0
for h in "${HOSTS[@]}"; do
  log "---- ${h} ----" "$C_B"
  if ! ssh -o BatchMode=yes -o ConnectTimeout=5 "${SSH_USER}@${h}" 'echo ok' >/dev/null 2>&1; then
    err "SSH 接続失敗: ${SSH_USER}@${h}"
    rc_all=1
    continue
  fi
  # 値と適用フラグを渡す
  if $CONFIRM; then DO_APPLY=1; else DO_APPLY=0; fi

  if ssh -T "${SSH_USER}@${h}" "VALUE='${SESSIONLOG}' DO_APPLY='${DO_APPLY}' bash -s" <<<"$REMOTE_SCRIPT"; then
    ok "${h}: 完了"
  else
    err "${h}: エラー発生"
    rc_all=1
  fi
done

exit "$rc_all"

