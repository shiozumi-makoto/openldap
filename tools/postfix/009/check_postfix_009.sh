#!/usr/bin/env bash
set -Eeuo pipefail

# ============================================================
# ovs-012 で管理している Postfix 設定と
# ovs-009:/etc/postfix の設定ファイルを比較する
#
# ・ファイル更新日時
# ・サイズ
# ・SHA256
# ・内容差分
# ・最後に全ファイルの比較サマリー
#
# ovs-009 のファイルは変更しません。
# ============================================================

SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-009}"

EXT_DIR="${EXT_DIR:-009}"
EXT="${EXT:-009}"

BASE_DIR="${BASE_DIR:-/usr/local/etc/openldap/tools/postfix}"
LOCAL_DIR="${BASE_DIR}/${EXT_DIR}"
REMOTE_DIR="/etc/postfix"

FILES=(
    "main.cf"
    "master.cf"
    "ldap-alias.cf"
    "ldap-users.cf"
    "virtual-regexp"
    "transport"
    "virtual"
    "sasl_passwd"
    "tls_policy"
)

# ------------------------------------------------------------
# 一時ディレクトリ
# ------------------------------------------------------------

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

# ------------------------------------------------------------
# 結果保存
# ------------------------------------------------------------

declare -A RESULTS

COUNT_OK=0
COUNT_DIFF=0
COUNT_LOCAL_ONLY=0
COUNT_REMOTE_ONLY=0
COUNT_NOT_FOUND=0


echo "============================================================"
echo " Postfix 設定ファイル比較"
echo "============================================================"
echo "管理元 : ${LOCAL_DIR}"
echo "比較先 : ${SSH_USER}@${HOST}:${REMOTE_DIR}"
echo "日時   : $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"
echo


# ------------------------------------------------------------
# SSH接続確認
# ------------------------------------------------------------

if ! ssh -o BatchMode=yes \
         -o ConnectTimeout=10 \
         -o StrictHostKeyChecking=accept-new \
         "${SSH_USER}@${HOST}" "true"; then

    echo "ERROR: ${HOST} にSSH接続できません。"
    exit 2
fi


# ============================================================
# ファイル比較
# ============================================================

for f in "${FILES[@]}"; do

    LOCAL_WITH_EXT="${LOCAL_DIR}/${f}.${EXT}"
    LOCAL_PLAIN="${LOCAL_DIR}/${f}"

    # --------------------------------------------------------
    # ローカル側ファイル決定
    #
    # xxx.009 があれば優先
    # 無ければ xxx を使用
    # --------------------------------------------------------

    if [[ -n "${EXT}" && -f "${LOCAL_WITH_EXT}" ]]; then
        LOCAL_FILE="${LOCAL_WITH_EXT}"

    elif [[ -f "${LOCAL_PLAIN}" ]]; then
        LOCAL_FILE="${LOCAL_PLAIN}"

    else
        LOCAL_FILE=""
    fi

    REMOTE_TMP="${TMP_DIR}/${f}"

    echo "------------------------------------------------------------"
    echo "FILE: ${f}"
    echo "------------------------------------------------------------"


    # ========================================================
    # LOCAL : ovs-012
    # ========================================================

    if [[ -n "${LOCAL_FILE}" ]]; then

        echo "[LOCAL : ovs-012]"

        stat -c \
'  file   : %n
  size   : %s bytes
  mtime  : %y' \
            "${LOCAL_FILE}"

        LOCAL_SHA="$(
            sha256sum "${LOCAL_FILE}" |
            awk '{print $1}'
        )"

        echo "  sha256 : ${LOCAL_SHA}"

    else

        echo "[LOCAL : ovs-012]"
        echo "  NOT FOUND"

        LOCAL_SHA=""

    fi

    echo


    # ========================================================
    # REMOTE : ovs-009
    # ========================================================

    if ssh -o BatchMode=yes \
           -o StrictHostKeyChecking=accept-new \
           "${SSH_USER}@${HOST}" \
           "test -f '${REMOTE_DIR}/${f}'"; then

        echo "[REMOTE: ${HOST}]"

        REMOTE_STAT="$(
            ssh -o BatchMode=yes \
                -o StrictHostKeyChecking=accept-new \
                "${SSH_USER}@${HOST}" \
                "stat -c '  file   : %n
  size   : %s bytes
  mtime  : %y' '${REMOTE_DIR}/${f}'"
        )"

        echo "${REMOTE_STAT}"

        REMOTE_SHA="$(
            ssh -o BatchMode=yes \
                -o StrictHostKeyChecking=accept-new \
                "${SSH_USER}@${HOST}" \
                "sha256sum '${REMOTE_DIR}/${f}'" |
            awk '{print $1}'
        )"

        echo "  sha256 : ${REMOTE_SHA}"

        # ----------------------------------------------------
        # diff 用に一時コピー
        # ----------------------------------------------------

        scp -q -p \
            -o BatchMode=yes \
            -o StrictHostKeyChecking=accept-new \
            "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}" \
            "${REMOTE_TMP}"

    else

        echo "[REMOTE: ${HOST}]"
        echo "  NOT FOUND"

        REMOTE_SHA=""

    fi

    echo


    # ========================================================
    # 判定
    # ========================================================

    if [[ -z "${LOCAL_FILE}" && -z "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  -- BOTH NOT FOUND"

        RESULTS["${f}"]="BOTH NOT FOUND"
        COUNT_NOT_FOUND=$((COUNT_NOT_FOUND + 1))


    elif [[ -z "${LOCAL_FILE}" ]]; then

        echo "[RESULT]"
        echo "  !! REMOTE ONLY"
        echo "  → ${HOST} にのみ存在します"

        RESULTS["${f}"]="REMOTE ONLY"
        COUNT_REMOTE_ONLY=$((COUNT_REMOTE_ONLY + 1))


    elif [[ -z "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  !! LOCAL ONLY"
        echo "  → ovs-012 にのみ存在します"

        RESULTS["${f}"]="LOCAL ONLY"
        COUNT_LOCAL_ONLY=$((COUNT_LOCAL_ONLY + 1))


    elif [[ "${LOCAL_SHA}" == "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  OK SAME"

        RESULTS["${f}"]="OK SAME"
        COUNT_OK=$((COUNT_OK + 1))


    else

        echo "[RESULT]"
        echo "  !! DIFFERENT"

        RESULTS["${f}"]="DIFFERENT"
        COUNT_DIFF=$((COUNT_DIFF + 1))

        echo
        echo "[DIFF]"

        diff -u \
            --label "ovs-012:${LOCAL_FILE}" \
            --label "${HOST}:${REMOTE_DIR}/${f}" \
            "${LOCAL_FILE}" \
            "${REMOTE_TMP}" || true

    fi

    echo

done


# ============================================================
# サマリー
# ============================================================

echo
echo "============================================================"
echo " Postfix 設定ファイル比較 サマリー"
echo "============================================================"

for f in "${FILES[@]}"; do

    STATUS="${RESULTS[$f]:-UNKNOWN}"

    case "${STATUS}" in

        "OK SAME")
            printf "%-20s : OK SAME\n" "${f}"
            ;;

        "DIFFERENT")
            printf "%-20s : !! DIFFERENT\n" "${f}"
            ;;

        "LOCAL ONLY")
            printf "%-20s : !! LOCAL ONLY\n" "${f}"
            ;;

        "REMOTE ONLY")
            printf "%-20s : !! REMOTE ONLY\n" "${f}"
            ;;

        "BOTH NOT FOUND")
            printf "%-20s : -- BOTH NOT FOUND\n" "${f}"
            ;;

        *)
            printf "%-20s : ?? UNKNOWN\n" "${f}"
            ;;

    esac

done


echo "------------------------------------------------------------"

printf "%-14s : %d\n" "OK"          "${COUNT_OK}"
printf "%-14s : %d\n" "DIFFERENT"   "${COUNT_DIFF}"
printf "%-14s : %d\n" "LOCAL ONLY"  "${COUNT_LOCAL_ONLY}"
printf "%-14s : %d\n" "REMOTE ONLY" "${COUNT_REMOTE_ONLY}"
printf "%-14s : %d\n" "NOT FOUND"   "${COUNT_NOT_FOUND}"

echo "------------------------------------------------------------"


TOTAL_ERROR=$((${COUNT_DIFF:-0}+${COUNT_LOCAL_ONLY:-0}+${COUNT_REMOTE_ONLY:-0}+${COUNT_NOT_FOUND:-0}))

if [[ "${TOTAL_ERROR}" -eq 0 ]]; then

    echo
    echo "RESULT:"
    echo "  ★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★"
    echo "  ★ すべての Postfix 設定ファイルが一致しています ★"
    echo "  ★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★"
    echo

    EXIT_CODE=0

else

    echo
    echo "RESULT:"
    echo "  !! ${TOTAL_ERROR} ファイルに差異・不足があります"
    echo

    if [[ "${COUNT_DIFF}" -gt 0 ]]; then
        echo "  内容が異なるファイル : ${COUNT_DIFF}"
    fi

    if [[ "${COUNT_LOCAL_ONLY}" -gt 0 ]]; then
        echo "  ovs-012 のみ          : ${COUNT_LOCAL_ONLY}"
    fi

    if [[ "${COUNT_REMOTE_ONLY}" -gt 0 ]]; then
        echo "  ${HOST} のみ         : ${COUNT_REMOTE_ONLY}"
    fi

    if [[ "${COUNT_NOT_FOUND}" -gt 0 ]]; then
        echo "  両方に存在しない      : ${COUNT_NOT_FOUND}"
    fi

    echo

    EXIT_CODE=1

fi


echo "============================================================"
echo " 比較完了 : $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"

exit "${EXIT_CODE}"
