#!/usr/bin/env bash
# Mount ovs-012:/home to /ovs012_home on ovs-002 (NFSv4)
# + (optional) Configure /etc/samba/smb.conf on ovs-002
#
# Features:
#   - Dry-run by default; apply with --confirm
#   - Idempotent (exports/fstab/smb.conf are appended or templated safely)
#   - Partial runs: --skip-server / --skip-client
#   - Rollback: --uninstall (unmount + remove fstab entry, and revert exports line)
#   - Samba on client (ovs-002):
#       --with-smbconf       : create minimal /etc/samba/smb.conf (client-friendly)
#       --share-ovs012-home  : export /ovs012_home as SMB share [ovs012_home] (off by default)
#
# Usage:
#   ./mount_ovs012_home.sh                  # dry-run
#   ./mount_ovs012_home.sh --confirm        # apply NFS mount
#   ./mount_ovs012_home.sh --confirm --with-smbconf
#   ./mount_ovs012_home.sh --confirm --with-smbconf --share-ovs012-home
#   ./mount_ovs012_home.sh --confirm --skip-server   # client-only
#   ./mount_ovs012_home.sh --confirm --skip-client   # server-only
#   ./mount_ovs012_home.sh --uninstall --confirm     # rollback
#
set -Eeuo pipefail

# ===== Defaults =====
SERVER_HOST="ovs-012"
CLIENT_HOST="ovs-002"
EXPORT_PATH="/home"
MOUNT_POINT="/ovs012_home"
NFS_TYPE="nfs4"
NFS_OPTS="defaults,_netdev,vers=4.2,timeo=600,retrans=2"
FIREWALL=1

# Samba on client options
WITH_SMBCONF=0         # --with-smbconf でON
SHARE_OVS012_HOME=0    # --share-ovs012-home でON
SMB_WORKGROUP="ESMILE"
SMB_GLOBAL_TEMPLATE="/tmp/smb.conf.global.$$"
SMB_CONF_PATH="/etc/samba/smb.conf"

DRYRUN=1
UNINSTALL=0
SKIP_SERVER=0
SKIP_CLIENT=0

LOG_DIR="/root/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="${LOG_DIR}/mount_ovs012_home_$(date +%Y%m%d_%H%M%S).log"

usage() {
  cat <<USG
Usage:
  $0 [--confirm] [--uninstall] [--skip-server] [--skip-client]
     [--server=ovs-012] [--client=ovs-002] [--mount=/ovs012_home]
     [--no-firewall] [--with-smbconf] [--share-ovs012-home]

Notes:
  * Default is DRY-RUN. Use --confirm to apply.
  * This script configures NFS mount. Samba client/server config on ovs-002 is optional.
USG
}

# ===== Parse args =====
for a in "$@"; do
  case "$a" in
    --confirm) DRYRUN=0 ;;
    --uninstall) UNINSTALL=1 ;;
    --skip-server) SKIP_SERVER=1 ;;
    --skip-client) SKIP_CLIENT=1 ;;
    --server=*) SERVER_HOST="${a#*=}" ;;
    --client=*) CLIENT_HOST="${a#*=}" ;;
    --mount=*)  MOUNT_POINT="${a#*=}" ;;
    --no-firewall) FIREWALL=0 ;;
    --with-smbconf) WITH_SMBCONF=1 ;;
    --share-ovs012-home) WITH_SMBCONF=1; SHARE_OVS012_HOME=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[WARN] Unknown arg: $a" ;;
  esac
done

# ===== Helpers =====
log() { echo -e "$@" | tee -a "$LOG_FILE" >&2; }
run() { local cmd="$1"; if ((DRYRUN)); then log "[DRYRUN] $cmd"; else log "[EXEC] $cmd"; eval "$cmd"; fi; }
rssh(){ local host="$1" cmd="$2"; if ((DRYRUN)); then log "[DRYRUN][$host] $cmd"; else ssh -o BatchMode=yes -o StrictHostKeyChecking=no "root@$host" "$cmd"; fi; }
rcp(){ local src="$1" dst="$2"; if ((DRYRUN)); then log "[DRYRUN] scp $src $dst"; else scp -q "$src" "$dst"; fi; }

log "[INFO] Start $(date)"
log "[INFO] server=$SERVER_HOST export=$EXPORT_PATH  client=$CLIENT_HOST mount=$MOUNT_POINT type=$NFS_TYPE"
log "[INFO] mode: DRYRUN=$DRYRUN UNINSTALL=$UNINSTALL SKIP_SERVER=$SKIP_SERVER SKIP_CLIENT=$SKIP_CLIENT WITH_SMBCONF=$WITH_SMBCONF SHARE=$SHARE_OVS012_HOME"

# ===== Server (ovs-012) =====
server_install() {
  log "\n=== [SERVER:$SERVER_HOST] NFS export for $EXPORT_PATH ==="
  rssh "$SERVER_HOST" "command -v dnf >/dev/null 2>&1 && dnf -y install nfs-utils || yum -y install nfs-utils"
  rssh "$SERVER_HOST" "systemctl enable --now nfs-server"

  local exp="/etc/exports"
  rssh "$SERVER_HOST" "test -d '$(dirname $exp)' && touch $exp && cp -a $exp ${exp}.bak_$(date +%F_%H%M%S)"
  local line="$EXPORT_PATH  $CLIENT_HOST(rw,sync,no_subtree_check,no_root_squash,sec=sys)"
  rssh "$SERVER_HOST" "grep -qE '^[[:space:]]*${EXPORT_PATH}[[:space:]]+${CLIENT_HOST}\\(' $exp || echo '$line' >> $exp"
  rssh "$SERVER_HOST" "exportfs -ra; exportfs -v"

  if ((FIREWALL)); then
    rssh "$SERVER_HOST" "systemctl is-active firewalld >/dev/null 2>&1 && firewall-cmd --add-service=nfs --permanent || true"
    rssh "$SERVER_HOST" "systemctl is-active firewalld >/dev/null 2>&1 && firewall-cmd --reload || true"
  fi
}

server_uninstall() {
  log "\n=== [SERVER:$SERVER_HOST] rollback exports entry ==="
  local exp="/etc/exports"
  rssh "$SERVER_HOST" "cp -a $exp ${exp}.bak_$(date +%F_%H%M%S) && awk '!/^[[:space:]]*${EXPORT_PATH}[[:space:]]+${CLIENT_HOST}\\(/ {print}' $exp > /tmp/exports.new && mv /tmp/exports.new $exp && exportfs -ra && exportfs -v"
}

# ===== Client (ovs-002) NFS =====
client_install() {
  log "\n=== [CLIENT:$CLIENT_HOST] mount ${SERVER_HOST}:${EXPORT_PATH} -> ${MOUNT_POINT} ==="
  rssh "$CLIENT_HOST" "command -v dnf >/div/null 2>&1 && dnf -y install nfs-utils || yum -y install nfs-utils"
  rssh "$CLIENT_HOST" "systemctl enable --now remote-fs.target >/dev/null 2>&1 || true"

  rssh "$CLIENT_HOST" "mkdir -p '$MOUNT_POINT'"

  local fstab="/etc/fstab"
  local fline="${SERVER_HOST}:${EXPORT_PATH}  ${MOUNT_POINT}  ${NFS_TYPE}  ${NFS_OPTS}  0  0"
  rssh "$CLIENT_HOST" "cp -a $fstab ${fstab}.bak_$(date +%F_%H%M%S)"
  rssh "$CLIENT_HOST" "grep -qE '^[[:space:]]*${SERVER_HOST}:${EXPORT_PATH}[[:space:]]+${MOUNT_POINT}[[:space:]]+${NFS_TYPE}' $fstab || echo '$fline' >> $fstab"

  rssh "$CLIENT_HOST" "mountpoint -q '${MOUNT_POINT}' && umount '${MOUNT_POINT}' || true"
  rssh "$CLIENT_HOST" "mount '${MOUNT_POINT}' || mount -a"
  rssh "$CLIENT_HOST" "mount | grep ' ${MOUNT_POINT} ' || { echo '[ERROR] mount failed'; exit 1; }"
  rssh "$CLIENT_HOST" "df -h '${MOUNT_POINT}' | sed -n '1,2p'"
}

client_uninstall() {
  log "\n=== [CLIENT:$CLIENT_HOST] unmount & remove fstab line ==="
  local fstab="/etc/fstab"
  rssh "$CLIENT_HOST" "umount '${MOUNT_POINT}' || true"
  rssh "$CLIENT_HOST" "cp -a $fstab ${fstab}.bak_$(date +%F_%H%M%S) && awk '!/^[[:space:]]*${SERVER_HOST}:${EXPORT_PATH}[[:space:]]+${MOUNT_POINT}[[:space:]]+${NFS_TYPE}/ {print}' $fstab > /tmp/fstab.new && mv /tmp/fstab.new $fstab"
}

# ===== Client (ovs-002) Samba config =====
client_smbconf_install() {
  log "\n=== [CLIENT:$CLIENT_HOST] configure /etc/samba/smb.conf (minimal) ==="
  # packages
  rssh "$CLIENT_HOST" "command -v dnf >/dev/null 2>&1 && dnf -y install samba samba-client || yum -y install samba samba-client"
  # backup and template
  rssh "$CLIENT_HOST" "test -f $SMB_CONF_PATH && cp -a $SMB_CONF_PATH ${SMB_CONF_PATH}.bak_$(date +%F_%H%M%S) || true"

  cat > "$SMB_GLOBAL_TEMPLATE" <<EOF
[global]
  workgroup = ${SMB_WORKGROUP}
  server string = ovs-002 Samba (client-optimized)
  # Disable old NetBIOS/SMB1
  smb ports = 445
  disable netbios = yes
  server min protocol = SMB2_10
  server max protocol = SMB3_11
  client min protocol = SMB2_10
  client max protocol = SMB3_11
  # Encoding
  dos charset = CP932
  unix charset = UTF-8
  # Name resolution preference
  name resolve order = host bcast
  # Performance/attrs
  load printers = no
  printing = bsd
  printcap name = /dev/null
  map to guest = Never
  # ACL/xattr (for client-side tools)
  vfs objects = acl_xattr
  map acl inherit = yes
  store dos attributes = yes
  # Logs (moderate)
  log file = /var/log/samba/log.%m
  max log size = 1000
EOF

  rcp "$SMB_GLOBAL_TEMPLATE" "root@$CLIENT_HOST:/tmp/smb.conf"
  rssh "$CLIENT_HOST" "install -m 0644 /tmp/smb.conf $SMB_CONF_PATH"

  if ((SHARE_OVS012_HOME)); then
    log "[INFO][$CLIENT_HOST] add share [ovs012_home] -> ${MOUNT_POINT}"
    rssh "$CLIENT_HOST" "cat >> $SMB_CONF_PATH <<'SMBEOF'

[ovs012_home]
  comment = Mirror of ${SERVER_HOST}:${EXPORT_PATH}
  path = ${MOUNT_POINT}
  browseable = yes
  read only = no
  writable = yes
  create mask = 0664
  directory mask = 0775
  valid users = @users
  vfs objects = acl_xattr
  inherit acls = yes
SMBEOF"
    # enable service & firewall
    rssh "$CLIENT_HOST" "systemctl enable --now smb"
    if ((FIREWALL)); then
      rssh "$CLIENT_HOST" "systemctl is-active firewalld >/dev/null 2>&1 && firewall-cmd --add-service=samba --permanent || true"
      rssh "$CLIENT_HOST" "systemctl is-active firewalld >/dev/null 2>&1 && firewall-cmd --reload || true"
    fi
  fi

  # test configuration (does not fail the whole run if dry-run)
  rssh "$CLIENT_HOST" "test -x /usr/bin/testparm && testparm -s 2>/dev/null | sed -n '1,20p' || true"
}

# ===== Execute =====
if ((UNINSTALL)); then
  ((SKIP_CLIENT)) || client_uninstall
  ((SKIP_SERVER)) || server_uninstall
else
  ((SKIP_SERVER)) || server_install
  ((SKIP_CLIENT)) || client_install
  if ((WITH_SMBCONF)) && ((SKIP_CLIENT==0)); then
    client_smbconf_install
  fi
fi

log "\n[INFO] Done. Log: $LOG_FILE"
if ((DRYRUN)); then
  log "[HINT] Re-run with --confirm to apply."
fi


