#!/usr/bin/env bash
set -Eeuo pipefail

# ==== 設定（環境変数で上書き可）====
SSH_USER="${SSH_USER:-root}"
HOST="${HOST:-ovs-025}"

REMOTE_ETC="/etc/postfix"
REMOTE_BAK="/var/backups/postfix"
LOCAL_MAIN="./main.cf.025"
LOCAL_LALIAS="./ldap-alias.cf.025"
LOCAL_LUSERS="./ldap-users.cf.025"

RESTART="${RESTART:-1}"

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }
need(){ command -v "$1" >/dev/null 2>&1 || die "コマンドが見つかりません: $1"; }

check_local(){
  [[ -f "${LOCAL_MAIN}"  ]] || die "ローカル ${LOCAL_MAIN} が見つかりません"
#  [[ -f "${LOCAL_LALIAS}" ]] || die "ローカル ${LOCAL_LALIAS} が見つかりません"
#  [[ -f "${LOCAL_LUSERS}" ]] || die "ローカル ${LOCAL_LUSERS} が見つかりません"
}

backup_remote(){
  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS'
set -Eeuo pipefail
ETC="/etc/postfix"
BAK="/var/backups/postfix"
TS="$(date +%Y%m%d_%H%M%S)"
install -d -m 0755 "${BAK}/${TS}"

for f in main.cf ldap-alias.cf ldap-users.cf; do
  if [[ -f "${ETC}/${f}" ]]; then
    cp -a "${ETC}/${f}" "${BAK}/${TS}/${f}"
  fi
done
echo "BACKUP => ${BAK}/${TS}"
EOS
}

transfer_and_apply(){
  log "=== 転送（tmp）"
  scp -p "${LOCAL_MAIN}"   "${SSH_USER}@${HOST}:${REMOTE_ETC}/main.cf.tmp"

#  scp -p "${LOCAL_LALIAS}" "${SSH_USER}@${HOST}:${REMOTE_ETC}/ldap-alias.cf.tmp"
#  scp -p "${LOCAL_LUSERS}" "${SSH_USER}@${HOST}:${REMOTE_ETC}/ldap-users.cf.tmp"

  log "=== 反映 & パーミッション設定 & CA配置チェック"
  ssh "${SSH_USER}@${HOST}" bash -s <<'EOS'

set -Eeuo pipefail
ETC="/etc/postfix"

# 反映
# chown root:root "${ETC}/main.cf.tmp" "${ETC}/ldap-alias.cf.tmp" "${ETC}/ldap-users.cf.tmp"
# chmod 0644      "${ETC}/main.cf.tmp" "${ETC}/ldap-alias.cf.tmp" "${ETC}/ldap-users.cf.tmp"
chown root:root "${ETC}/main.cf.tmp"
chmod 0644      "${ETC}/main.cf.tmp"
mv -f "${ETC}/main.cf.tmp"        "${ETC}/main.cf"
# mv -f "${ETC}/ldap-alias.cf.tmp"  "${ETC}/ldap-alias.cf"
# mv -f "${ETC}/ldap-users.cf.tmp"  "${ETC}/ldap-users.cf"

# echo "${ETC}/main.cf.tmp ${ETC}/main.cf"
# exit

# CA の在りか確認（なければ社内CAの複製を試みる）
if [[ ! -r /etc/postfix/tls/ca.crt ]]; then
  install -d -m 0755 /etc/postfix/tls
  if [[ -r /usr/local/etc/openldap/certs/cacert.crt ]]; then
    cp -a /usr/local/etc/openldap/certs/cacert.crt /etc/postfix/tls/ca.crt
 fi
fi

# owned by root
chown -R root:root /etc/postfix

# 構文/設定チェック
postfix check

# 最小の必須表示
echo "----- postconf -n (short) -----"
postconf -n | egrep -i '^(myhostname|mydomain|mynetworks|relayhost|alias_maps|compatibility_level|smtpd_tls_|smtp_tls_|postscreen_)' || true

# 再読み込み
systemctl reload postfix 2>/dev/null || postfix reload

# LISTEN 状態とポート確認
echo "----- LISTEN ports -----"
ss -lntup | egrep ':(25|587)\b' || true

echo "Postfix 反映完了"
EOS
}

main(){
  need ssh; need scp
  check_local
  log "=== ${HOST} にバックアップ"
  backup_remote
  log "=== ファイル転送と反映"
  transfer_and_apply
  log "=== 完了"
}

main "$@"
