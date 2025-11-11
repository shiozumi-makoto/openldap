#!/usr/bin/env bash
#----------------------------------------------------------------------
# olcSizeLimit_show.sh
#  - 各ホストの cn=config から *mdb DB の olcSizeLimit / olcLimits を収集・表示
#  - {1}mdb / {2}mdb 混在OK（全 mdb をスキャン）
#  - 既存ツールの流儀を踏襲: ldapi 自動検出, SSH_OPTS, get_dn_remote 等
#----------------------------------------------------------------------
set -Eeuo pipefail
LANG=C
LC_ALL=C

# ===== 設定（環境変数で上書き可）=====
HOSTS="${HOSTS:-ovs-002 ovs-012 ovs-024 ovs-025 ovs-026 ovs-009 ovs-010 ovs-011}"
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"   # DB 絞り込みに使う（空でも可）
LDAPURI_ENC_REMOTE="${LDAPURI_ENC_REMOTE:-}" # 明示指定があれば優先
DETAIL="${DETAIL:-0}"                         # 1 で olcLimits を全行表示
SSH_OPTS="${SSH_OPTS:- -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=7}"
OUT_CSV="${OUT_CSV:-}"                        # CSV 出力先（空なら出さない）

ts(){ date '+%F %T'; }
log(){ echo "[$(ts)] $*"; }

# ===== ldapi 自動検出（既存スクリプトの流儀を踏襲）=====
auto_ldapi() {
  local host="$1"
  local -a guesses=(
    'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Fvar%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Frun%2Fldapi'
  )
  # /usr/local/openldap-*/var/run/ldapi も試す（ovs-009系など）
  local dyn
  dyn=$(ssh ${SSH_OPTS} "$host" "ls -1d /usr/local/openldap-*/var/run/ldapi 2>/dev/null | head -n1") || true
  if [[ -n "$dyn" ]]; then
    local enc="${dyn//\//%2F}"
    guesses=("ldapi://$enc" "${guesses[@]}")
  fi
  local u
  for u in "${guesses[@]}"; do
    if ssh ${SSH_OPTS} "$host" "ldapsearch -LLL -Y EXTERNAL -H '$u' -b cn=config dn -o ldif-wrap=no >/dev/null 2>&1"; then
      echo "$u"; return 0
    fi
  done
  echo ""
}

# ===== suffix を鍵に mdb/hdb/bdb を探索して DN を返す（見つからなければ空）=====
get_dn_remote() {
  local host="$1" uri="$2" suffix="$3"
  ssh ${SSH_OPTS} "$host" bash -s -- "$uri" "$suffix" <<'EOS'
set -Eeuo pipefail
LDAPURI="$1"; SUFFIX="$2"

# SUFFIX 指定があればそれを優先、無ければ mdb 全列挙
if [[ -n "$SUFFIX" ]]; then
  for oc in olcMdbConfig olcHdbConfig olcBdbConfig; do
    dn=$(ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
          "(&(objectClass=$oc)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
        | awk "/^dn: /{print substr(\$0,5)}" )
    [[ -n "$dn" ]] && { echo "$dn"; exit 0; }
  done
  # 見つからない場合は空
  exit 0
else
  # SUFFIX が無ければ mdb 全件
  ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
    "(&(objectClass=olcDatabaseConfig)(olcDatabase=*mdb))" dn -o ldif-wrap=no 2>/dev/null \
  | awk '/^dn: /{print substr($0,5)}'
fi
EOS
}

# ===== 見出し =====
echo "=== olcSizeLimit / olcLimits checker (mdb only) ==="
echo "Targets : ${HOSTS}"
[[ -n "$SUFFIX" ]] && echo "SUFFIX  : ${SUFFIX}" || echo "SUFFIX  : (not specified / list all mdb)"
[[ -n "$OUT_CSV" ]] && echo "CSV     : ${OUT_CSV}"
echo

# CSV ヘッダ
if [[ -n "$OUT_CSV" ]]; then
  echo "host,dn,suffix,olcSizeLimit,olcLimits_firstLine,olcLimits_count" > "${OUT_CSV}"
fi

RET=0

for H in ${HOSTS}; do
  echo "==== [${H}] ===="

  # ldapi 決定
  LDAPI="${LDAPURI_ENC_REMOTE}"
  if [[ -z "$LDAPI" ]]; then
    LDAPI="$(auto_ldapi "$H")"
    if [[ -z "$LDAPI" ]]; then
      log "[ERROR] ${H}: ldapi が見つからずスキップ"
      RET=1; echo; continue
    fi
    log "[INFO] ${H}: AUTO ldapi-remote: $LDAPI"
  fi

  # 対象 DN 群の取得
  mapfile -t DNS < <(get_dn_remote "$H" "$LDAPI" "$SUFFIX" || true)
  if (( ${#DNS[@]} == 0 )); then
    log "[WARN] ${H}: 対象 mdb データベースが見つかりませんでした（SUFFIX=${SUFFIX:-*}）"
    echo; continue
  fi

  for DN in "${DNS[@]}"; do
    [[ -z "$DN" ]] && continue

    # 必要属性を収集
    RAW=$(ssh ${SSH_OPTS} "$H" \
      "ldapsearch -LLL -Y EXTERNAL -H '$LDAPI' -b '$DN' olcSuffix olcSizeLimit olcLimits -o ldif-wrap=no" 2>/dev/null || true)

    # パース
    suffix=$(printf '%s\n' "$RAW" | awk -F': ' '/^olcSuffix: /{print $2; exit}')
    sizel=$(printf  '%s\n' "$RAW" | awk -F': ' '/^olcSizeLimit: /{print $2; exit}')
    # 複数行の olcLimits（0..n行）
    limits_all=$(printf '%s\n' "$RAW" | awk -F': ' '/^olcLimits: /{print $2}')
    limits_first=$(printf '%s\n' "$limits_all" | head -n1)
    limits_count=$(printf '%s\n' "$limits_all" | grep -c . || true)

    [[ -z "$suffix" ]] && suffix="(absent)"
    [[ -z "$sizel"  ]] && sizel="(absent)"
    [[ -z "$limits_first" ]] && limits_first="(absent)"

    echo "  DB: ${DN}"
    echo "      suffix      : ${suffix}"
    echo "      olcSizeLimit: ${sizel}"

    if [[ "$DETAIL" == "1" ]]; then
      if [[ "$limits_count" -gt 0 ]]; then
        echo "      olcLimits   :"
        i=0
        while IFS= read -r line; do
          ((i++))
          echo "        - ${line}"
        done <<< "$limits_all"
      else
        echo "      olcLimits   : (absent)"
      fi
    else
      if [[ "$limits_count" -gt 0 ]]; then
        echo "      olcLimits   : ${limits_first}  (… ${limits_count} line(s), DETAIL=1 で全表示)"
      else
        echo "      olcLimits   : (absent)"
      fi
    fi
    echo

    # CSV 追記
    if [[ -n "$OUT_CSV" ]]; then
      # カンマとダブルクォートの簡易エスケープ
      esc() { printf '%s' "$1" | sed 's/"/""/g'; }
      printf '"%s","%s","%s","%s","%s","%s"\n' \
        "$H" "$(esc "$DN")" "$(esc "$suffix")" "$(esc "$sizel")" "$(esc "$limits_first")" "$limits_count" \
        >> "${OUT_CSV}"
    fi

    # sizel 未設定や limits 無しで終了コードに反映したい場合（任意）
    # [[ "$sizel" == "(absent)" ]] && RET=2
  done

  echo
done

exit $RET
