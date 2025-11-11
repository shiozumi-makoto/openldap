#!/usr/bin/env bash
#----------------------------------------------------------------------
# olcSizeLimit_sync.sh
#  - mdb の olcSizeLimit/olcLimits をホスト横断で「設定・統一」
#  - 既存値を尊重しつつ不足分のみ追加/置換（冪等）
#  - {1}mdb / {2}mdb 混在OK。SUFFIX で対象DBを限定
# 既定: DRY_RUN=1（変更は出力のみ）。APPLY=1 で実際に ldapmodify
#----------------------------------------------------------------------

set -Eeuo pipefail
LANG=C; LC_ALL=C

# ===== 設定（環境変数で上書き）=====
HOSTS="${HOSTS:-ovs-002 ovs-012 ovs-024 ovs-025 ovs-026 ovs-009 ovs-010 ovs-011}"
SUFFIX="${SUFFIX:-dc=e-smile,dc=ne,dc=jp}"
SIZE="${SIZE:-50000}"                        # olcSizeLimit に入れる数値
APPLY="${APPLY:-0}"                          # 1で適用、0はDRY-RUN
SSH_OPTS="${SSH_OPTS:- -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=7}"
LDAPURI_ENC_REMOTE="${LDAPURI_ENC_REMOTE:-}" # 明示指定があれば優先
BACKUP_DIR_REMOTE="${BACKUP_DIR_REMOTE:-/root/ldap-schema-bak}"
TS="$(date +%Y%m%d%H%M%S)"

# 追加したい olcLimits の行（必要なら増やす）
LIMITS_WANTED=(
  'dn.exact="cn=Admin,dc=e-smile,dc=ne,dc=jp" size=unlimited time=unlimited'
  'dn.exact="gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth" size=unlimited time=unlimited'
)

ts(){ date '+%F %T'; }
log(){ echo "[$(ts)] $*"; }

auto_ldapi() {
  local host="$1"
  local -a guesses=(
    'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Fvar%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Frun%2Fopenldap%2Fslapd.sock'
    'ldapi://%2Fvar%2Frun%2Fldapi'
    'ldapi://%2Frun%2Fldapi'
  )
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


get_dn_remote() {
  local host="$1" uri="$2" suffix="$3"
  ssh ${SSH_OPTS} "$host" bash -s -- "$uri" "$suffix" <<'EOS'
set -Eeuo pipefail
LDAPURI="$1"; SUFFIX="$2"

# SUFFIX を指定して、その suffix を持つ mdb/hdb/bdb を拾う（show版と同じ流儀）
for oc in olcMdbConfig olcHdbConfig olcBdbConfig; do
  ldapsearch -LLL -Y EXTERNAL -H "$LDAPURI" -b cn=config \
    "(&(objectClass=$oc)(olcSuffix=$SUFFIX))" dn -o ldif-wrap=no 2>/dev/null \
  | awk '/^dn: /{print substr($0,5)}'
done
EOS
}


# ldif を安全に適用
apply_ldif() {
  local host="$1" uri="$2" ldif="$3"

  if [[ "${APPLY}" != "1" ]]; then
    echo "----- DRY-RUN (ldapmodify on ${host}) -----"
    echo "${ldif}"
    echo "----- DRY-RUN end -----"
    return 0
  fi

  # バックアップ（cn=config のスナップショット）
  ssh ${SSH_OPTS} "$host" "mkdir -p '${BACKUP_DIR_REMOTE}' && \
    ldapsearch -LLL -Y EXTERNAL -H '$uri' -b cn=config -o ldif-wrap=no > '${BACKUP_DIR_REMOTE}/cn_config.${TS}.ldif'"

  # 適用
  ssh ${SSH_OPTS} "$host" "ldapmodify -Y EXTERNAL -H '$uri' -o ldif-wrap=no" <<< "${ldif}"
}

echo "=== olcSizeLimit / olcLimits synchronizer (mdb) ==="
echo "Targets : ${HOSTS}"
echo "SUFFIX  : ${SUFFIX}"
echo "SIZE    : ${SIZE}"
echo "APPLY   : ${APPLY}  (0=DRY-RUN, 1=apply)"
echo

for H in ${HOSTS}; do
  echo "==== [${H}] ===="

  LDAPI="${LDAPURI_ENC_REMOTE}"
  if [[ -z "$LDAPI" ]]; then
    LDAPI="$(auto_ldapi "$H")"
    if [[ -z "$LDAPI" ]]; then
      log "[ERROR] ${H}: ldapi 見つからずスキップ"
      echo; continue
    fi
    log "[INFO] ${H}: AUTO ldapi-remote: $LDAPI"
  fi

  mapfile -t DNS < <(get_dn_remote "$H" "$LDAPI" "$SUFFIX" || true)
  if (( ${#DNS[@]} == 0 )); then
    log "[WARN] ${H}: SUFFIX=${SUFFIX} の mdb DB が見つかりませんでした"
    echo; continue
  fi

  for DN in "${DNS[@]}"; do
    [[ -z "$DN" ]] && continue
    echo "  -> Target DB: ${DN}"

    RAW=$(ssh ${SSH_OPTS} "$H" "ldapsearch -LLL -Y EXTERNAL -H '$LDAPI' -b '$DN' olcSizeLimit olcLimits -o ldif-wrap=no" 2>/dev/null || true)
    cur_size=$(printf '%s\n' "$RAW" | awk -F': ' '/^olcSizeLimit: /{print $2; exit}')
    # 既存の olcLimits 全行を配列化
    # readarray -t cur_limits < <(printf '%s\n' "$RAW" | awk -F': ' '/^olcLimits: /{print $2}')
    # 既存の olcLimits 全行を配列化（先頭の {n} を除去して正規化）
readarray -t cur_limits < <(
  printf '%s\n' "$RAW" \
  | awk -F': ' '/^olcLimits: /{print $2}' \
  | sed -E 's/^\{[0-9]+\}//'
)


    # --- olcSizeLimit の LDIF（置換 or 追加）---
    ldif=""
    if [[ -n "$cur_size" && "$cur_size" != "$SIZE" ]]; then
      ldif+="dn: ${DN}
changetype: modify
replace: olcSizeLimit
olcSizeLimit: ${SIZE}

"
      echo "    plan: replace olcSizeLimit ${cur_size} -> ${SIZE}"
    elif [[ -z "$cur_size" ]]; then
      ldif+="dn: ${DN}
changetype: modify
add: olcSizeLimit
olcSizeLimit: ${SIZE}

"
      echo "    plan: add olcSizeLimit ${SIZE}"
    else
      echo "    ok  : olcSizeLimit already ${SIZE}"
    fi

    # --- olcLimits の LDIF（不足分のみ add）---
    for want in "${LIMITS_WANTED[@]}"; do
      found=0
      for have in "${cur_limits[@]:-}"; do
        if [[ "$have" == "$want" ]]; then found=1; break; fi
      done
      if [[ $found -eq 0 ]]; then
        ldif+="dn: ${DN}
changetype: modify
add: olcLimits
olcLimits: ${want}

"
        echo "    plan: add olcLimits: ${want}"
      else
        echo "    ok  : olcLimits exists: ${want}"
      fi
    done

    if [[ -z "$ldif" ]]; then
      echo "    nothing to do."
    else
      apply_ldif "$H" "$LDAPI" "$ldif"
    fi

    echo
  done

  echo
done

echo "done."
