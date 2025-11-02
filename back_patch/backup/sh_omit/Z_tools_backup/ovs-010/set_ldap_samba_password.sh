#!/usr/bin/env bash
set -euo pipefail

# set_ldap_samba_password.sh
# LDAP + Samba パスワード自動設定スクリプト
# - sambaNTPassword (MD4 of UTF-16LE password)
# - sambaPwdLastSet (UNIX time)
# - userPassword (SSHA)
#
# Usage:
#   ./set_ldap_samba_password.sh -u UID -p PASSWORD [-h HOST] [-w BINDPW] [-t]
# Example:
#   ./set_ldap_samba_password.sh -u shiozumi -p 'Makoto87426598' -h 192.168.61.12

print_usage() {
  cat <<USAGE
Usage: $0 -u UID -p PASSWORD [options]
  -u UID       : LDAP uid (required)
  -p PASSWORD  : new plain password (required)
  -h HOST      : LDAP host (default: 127.0.0.1)
  -P PORT      : LDAP port (default: 389)
  -b BIND_DN   : Bind DN (default: cn=Admin,dc=e-smile,dc=ne,dc=jp)
  -w BIND_PW   : Bind password (prompt if omitted)
  -B BASE_DN   : Base DN (default: dc=e-smile,dc=ne,dc=jp)
  -o OU        : OU (default: ou=Users)
  -t           : Test mode (no ldapmodify)
USAGE
}

LDAP_HOST="127.0.0.1"
PORT=389
BIND_DN="cn=Admin,dc=e-smile,dc=ne,dc=jp"
BIND_PW=""
BASE_DN="dc=e-smile,dc=ne,dc=jp"
OU="ou=Users"
TEST_ONLY=0
TARGET_UID=""
PASSWORD=""

while getopts ":u:p:h:P:b:w:B:o:t" opt; do
  case ${opt} in
    u) TARGET_UID="${OPTARG}" ;;
    p) PASSWORD="${OPTARG}" ;;
    h) LDAP_HOST="${OPTARG}" ;;
    P) PORT="${OPTARG}" ;;
    b) BIND_DN="${OPTARG}" ;;
    w) BIND_PW="${OPTARG}" ;;
    B) BASE_DN="${OPTARG}" ;;
    o) OU="${OPTARG}" ;;
    t) TEST_ONLY=1 ;;
    \?) echo "Invalid option: -$OPTARG" >&2; print_usage; exit 1 ;;
    :) echo "Option -$OPTARG requires an argument." >&2; print_usage; exit 1 ;;
  esac
done

if [ -z "${TARGET_UID}" ] || [ -z "${PASSWORD}" ]; then
  echo "TARGET_UID (-u) and PASSWORD (-p) are required." >&2
  print_usage
  exit 1
fi

if [ -z "${BIND_PW}" ]; then
  read -s -p "LDAP bind password for '${BIND_DN}': " BIND_PW
  echo
fi

for cmd in python3 slappasswd ldapmodify ldapwhoami; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERROR: '$cmd' not found"; exit 1; }
done

USER_DN="uid=${TARGET_UID},${OU},${BASE_DN}"
LDAP_URI="ldap://${LDAP_HOST}:${PORT}"

echo "[INFO] LDAP: ${LDAP_URI}"
echo "[INFO] User DN: ${USER_DN}"

# --- NT ハッシュ生成 ---
NT_HASH=$(python3 - <<'PY' "$PASSWORD"
import sys, struct
def _F(x,y,z): return ((x & y) | (~x & z)) & 0xFFFFFFFF
def _G(x,y,z): return ((x & y) | (x & z) | (y & z)) & 0xFFFFFFFF
def _H(x,y,z): return (x ^ y ^ z) & 0xFFFFFFFF
def _rol(v, s): return ((v << s) | (v >> (32 - s))) & 0xFFFFFFFF
def md4(data: bytes) -> bytes:
    A,B,C,D = 0x67452301,0xEFCDAB89,0x98BADCFE,0x10325476
    orig_len_bits = (len(data)*8) & 0xFFFFFFFFFFFFFFFF
    data += b"\x80"
    while (len(data)%64)!=56: data += b"\x00"
    data += struct.pack("<Q", orig_len_bits)
    for i in range(0,len(data),64):
        X=list(struct.unpack("<16I", data[i:i+64]))
        AA,BB,CC,DD=A,B,C,D
        S=[3,7,11,19]
        A=_rol((A+_F(B,C,D)+X[0])&0xFFFFFFFF,S[0])
        D=_rol((D+_F(A,B,C)+X[1])&0xFFFFFFFF,S[1])
        C=_rol((C+_F(D,A,B)+X[2])&0xFFFFFFFF,S[2])
        B=_rol((B+_F(C,D,A)+X[3])&0xFFFFFFFF,S[3])
        A=_rol((A+_F(B,C,D)+X[4])&0xFFFFFFFF,S[0])
        D=_rol((D+_F(A,B,C)+X[5])&0xFFFFFFFF,S[1])
        C=_rol((C+_F(D,A,B)+X[6])&0xFFFFFFFF,S[2])
        B=_rol((B+_F(C,D,A)+X[7])&0xFFFFFFFF,S[3])
        A=_rol((A+_F(B,C,D)+X[8])&0xFFFFFFFF,S[0])
        D=_rol((D+_F(A,B,C)+X[9])&0xFFFFFFFF,S[1])
        C=_rol((C+_F(D,A,B)+X[10])&0xFFFFFFFF,S[2])
        B=_rol((B+_F(C,D,A)+X[11])&0xFFFFFFFF,S[3])
        A=_rol((A+_F(B,C,D)+X[12])&0xFFFFFFFF,S[0])
        D=_rol((D+_F(A,B,C)+X[13])&0xFFFFFFFF,S[1])
        C=_rol((C+_F(D,A,B)+X[14])&0xFFFFFFFF,S[2])
        B=_rol((B+_F(C,D,A)+X[15])&0xFFFFFFFF,S[3])
        S=[3,5,9,13]
        for i2 in range(0,16,4):
            A=_rol((A+_G(B,C,D)+X[i2]+0x5A827999)&0xFFFFFFFF,S[0])
            D=_rol((D+_G(A,B,C)+X[(i2+1)%16]+0x5A827999)&0xFFFFFFFF,S[1])
            C=_rol((C+_G(D,A,B)+X[(i2+2)%16]+0x5A827999)&0xFFFFFFFF,S[2])
            B=_rol((B+_G(C,D,A)+X[(i2+3)%16]+0x5A827999)&0xFFFFFFFF,S[3])
        S=[3,9,11,15]
        for i2 in range(0,16,4):
            A=_rol((A+_H(B,C,D)+X[i2]+0x6ED9EBA1)&0xFFFFFFFF,S[0])
            D=_rol((D+_H(A,B,C)+X[(i2+1)%16]+0x6ED9EBA1)&0xFFFFFFFF,S[1])
            C=_rol((C+_H(D,A,B)+X[(i2+2)%16]+0x6ED9EBA1)&0xFFFFFFFF,S[2])
            B=_rol((B+_H(C,D,A)+X[(i2+3)%16]+0x6ED9EBA1)&0xFFFFFFFF,S[3])
        A=(A+AA)&0xFFFFFFFF; B=(B+BB)&0xFFFFFFFF
        C=(C+CC)&0xFFFFFFFF; D=(D+DD)&0xFFFFFFFF
    return struct.pack("<4I",A,B,C,D)
pw = sys.argv[1].encode('utf-16le')
print(md4(pw).hex().upper())
PY
)
echo "[INFO] NT hash: $NT_HASH"

# --- SSHA 生成 ---
SSHA_PASS=$(slappasswd -h '{SSHA}' -s "${PASSWORD}")
echo "[INFO] SSHA: $SSHA_PASS"

# --- パスワード変更時刻（エポック秒） ---
EPOCH=$(date +%s)
echo "[INFO] sambaPwdLastSet: $EPOCH"

if [ "$TEST_ONLY" -eq 1 ]; then
  cat <<OUT
TEST ONLY
sambaNTPassword: ${NT_HASH}
userPassword: ${SSHA_PASS}
sambaPwdLastSet: ${EPOCH}
LDAP URI: ${LDAP_URI}
User DN : ${USER_DN}
OUT
  exit 0
fi

# --- LDAP 変更LDIF ---
TMP_LDIF=$(mktemp /tmp/ldap_mod_XXXX.ldif)
cat > "$TMP_LDIF" <<LDIF
dn: ${USER_DN}
changetype: modify
replace: sambaNTPassword
sambaNTPassword: ${NT_HASH}
-
replace: sambaPwdLastSet
sambaPwdLastSet: ${EPOCH}
-
replace: userPassword
userPassword: ${SSHA_PASS}
LDIF

echo "[INFO] ldapmodify を実行中..."
ldapmodify -x -H "${LDAP_URI}" -D "${BIND_DN}" -w "${BIND_PW}" -f "${TMP_LDIF}"
rm -f "$TMP_LDIF"
echo "[INFO] LDAP 更新完了。"

# --- 確認 ---
echo "[INFO] ユーザーで bind 確認中..."
if ldapwhoami -x -H "${LDAP_URI}" -D "${USER_DN}" -w "${PASSWORD}" >/dev/null 2>&1; then
  echo "[OK] ldapwhoami 成功：パスワード反映済み。"
else
  echo "[WARN] ldapwhoami 失敗。パスワードを再確認してください。" >&2
  exit 1
fi

