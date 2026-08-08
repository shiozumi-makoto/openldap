#!/usr/bin/env bash
set -u

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

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

echo "============================================================"
echo " Postfix 設定ファイル比較"
echo "============================================================"
echo "管理元 : ${LOCAL_DIR}"
echo "比較先 : ${SSH_USER}@${HOST}:${REMOTE_DIR}"
echo "日時   : $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================================"
echo

for f in "${FILES[@]}"; do

    LOCAL_WITH_EXT="${LOCAL_DIR}/${f}.${EXT}"
    LOCAL_PLAIN="${LOCAL_DIR}/${f}"

    if [[ -f "${LOCAL_WITH_EXT}" ]]; then
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

    #
    # ローカル（ovs-012）
    #
    if [[ -n "${LOCAL_FILE}" ]]; then
        echo "[LOCAL : ovs-012]"
        stat -c '  file   : %n
  size   : %s bytes
  mtime  : %y' "${LOCAL_FILE}"

        LOCAL_SHA="$(sha256sum "${LOCAL_FILE}" | awk '{print $1}')"
        echo "  sha256 : ${LOCAL_SHA}"
    else
        echo "[LOCAL : ovs-012]"
        echo "  NOT FOUND"
        LOCAL_SHA=""
    fi

    echo

    #
    # リモート（ovs-009）
    #
    if ssh -o StrictHostKeyChecking=accept-new \
        "${SSH_USER}@${HOST}" \
        "test -f '${REMOTE_DIR}/${f}'"; then

        echo "[REMOTE: ${HOST}]"

        ssh -o StrictHostKeyChecking=accept-new \
            "${SSH_USER}@${HOST}" \
            "stat -c '  file   : %n
  size   : %s bytes
  mtime  : %y' '${REMOTE_DIR}/${f}';
             sha256sum '${REMOTE_DIR}/${f}'" |
        while IFS= read -r line; do
            if [[ "${line}" =~ ^[0-9a-f]{64}[[:space:]] ]]; then
                echo "  sha256 : $(echo "${line}" | awk '{print $1}')"
            else
                echo "${line}"
            fi
        done

        REMOTE_SHA="$(
            ssh -o StrictHostKeyChecking=accept-new \
                "${SSH_USER}@${HOST}" \
                "sha256sum '${REMOTE_DIR}/${f}'" |
            awk '{print $1}'
        )"

        scp -q -p -o StrictHostKeyChecking=accept-new \
            "${SSH_USER}@${HOST}:${REMOTE_DIR}/${f}" \
            "${REMOTE_TMP}"

    else
        echo "[REMOTE: ${HOST}]"
        echo "  NOT FOUND"
        REMOTE_SHA=""
    fi

    echo

    #
    # 判定
    #
    if [[ -z "${LOCAL_FILE}" && -z "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  -- BOTH NOT FOUND"

    elif [[ -z "${LOCAL_FILE}" ]]; then

        echo "[RESULT]"
        echo "  !! REMOTE ONLY"
        echo "  → ovs-009 にのみ存在します"

    elif [[ -z "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  !! LOCAL ONLY"
        echo "  → ovs-012 にのみ存在します"

    elif [[ "${LOCAL_SHA}" == "${REMOTE_SHA}" ]]; then

        echo "[RESULT]"
        echo "  OK SAME"

    else

        echo "[RESULT]"
        echo "  !! DIFFERENT"

        echo
        echo "[DIFF]"
        diff -u \
            --label "ovs-012:${LOCAL_FILE}" \
            --label "ovs-009:${REMOTE_DIR}/${f}" \
            "${LOCAL_FILE}" "${REMOTE_TMP}" || true
    fi

    echo
done

echo "============================================================"
echo " 比較完了"
echo "============================================================"
