#!/usr/bin/env bash
set -Eeuo pipefail

HOSTS=("ovs-002" "ovs-024" "ovs-025" "ovs-026" "ovs-012")
SSH_USER="${SSH_USER:-root}"
TIMEOUT_SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=5"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

log(){ printf '[%s] %s\n' "$(date +%F\ %T)" "$*"; }
die(){ echo "ERROR: $*" >&2; exit 1; }

collect_one(){
  local h="$1"
  local out="$WORKDIR/$h.slapdV.txt"
  local mod="$WORKDIR/$h.modules.txt"

  # 英語化＆CR除去
  if ! ssh $TIMEOUT_SSH_OPTS "${SSH_USER}@${h}" 'LC_ALL=C slapd -VVV 2>&1 | tr -d "\r"' >"$out" ; then
    echo "FAILED" > "$out"
  fi

  # すべての module エントリから olcModuleLoad を収集（クォート簡素化）
  ssh $TIMEOUT_SSH_OPTS "${SSH_USER}@${h}" '
    for dn in $(ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "cn=config" "(objectClass=olcModuleList)" dn 2>/dev/null | sed -n "s/^dn: //p"); do
      ldapsearch -LLL -Y EXTERNAL -H ldapi:/// -b "$dn" olcModuleLoad 2>/dev/null
    done
  ' 2>/dev/null | awk -F': ' '$1=="olcModuleLoad"{print $2}' >"$mod" || true
}

parse_blocks(){
  local f="$1"
  awk '
    BEGIN{sec="";}

    /^Included static overlays:/ {sec="ov"; next}
    /^Included static backends:/ {sec="be"; next}
    /^Included / {sec=""; next}
    /^[[:space:]]*$/ {next}

    {
      if (sec=="ov" || sec=="be") {
        gsub(/^[ \t]+|[ \t]+$/, "", $0)
        if ($0 ~ /^[A-Za-z0-9_]+$/) {
          if (sec=="ov") printf "overlay\t%s\n", $0
          else            printf "backend\t%s\n", $0
        }
      }
    }
  ' "$f"
}

log "Collecting from hosts: ${HOSTS[*]}"
for h in "${HOSTS[@]}"; do
  log "  -> $h"
  collect_one "$h"
done

BASE="ovs-012"
[[ -f "$WORKDIR/$BASE.slapdV.txt" ]] || die "基準 $BASE の収集に失敗"
if grep -q '^FAILED$' "$WORKDIR/$BASE.slapdV.txt"; then die "基準 $BASE で slapd -VVV 実行失敗"; fi

BASE_OV="$WORKDIR/base.overlays"
BASE_BE="$WORKDIR/base.backends"
parse_blocks "$WORKDIR/$BASE.slapdV.txt" | awk -F'\t' '$1=="overlay"{print $2}' | sort -u > "$BASE_OV"
parse_blocks "$WORKDIR/$BASE.slapdV.txt" | awk -F'\t' '$1=="backend"{print $2}' | sort -u > "$BASE_BE"

echo
echo "===== 基準ホスト: $BASE ====="
echo "Static overlays:";  [[ -s "$BASE_OV" ]] && xargs -n8 echo < "$BASE_OV" || echo "(none)"
echo "Static backends:";  [[ -s "$BASE_BE" ]] && xargs -n8 echo < "$BASE_BE" || echo "(none)"
echo

for h in "${HOSTS[@]}"; do
  echo "===== Host: $h ====="
  if grep -q '^FAILED$' "$WORKDIR/$h.slapdV.txt"; then
    echo "(slapd -VVV 取得失敗)"; echo; continue
  fi
  CUR_OV="$WORKDIR/$h.overlays"
  CUR_BE="$WORKDIR/$h.backends"
  parse_blocks "$WORKDIR/$h.slapdV.txt" | awk -F'\t' '$1=="overlay"{print $2}' | sort -u > "$CUR_OV"
  parse_blocks "$WORKDIR/$h.slapdV.txt" | awk -F'\t' '$1=="backend"{print $2}' | sort -u > "$CUR_BE"

  echo "- Static overlays (missing vs BASE):"
  [[ -s "$BASE_OV" ]] && comm -23 "$BASE_OV" "$CUR_OV" | sed 's/^/  MISSING: /' || echo "  (BASE has none)"
  echo "- Static overlays (extra vs BASE):"
  [[ -s "$CUR_OV" ]]  && comm -13 "$BASE_OV" "$CUR_OV" | sed 's/^/  EXTRA:   /'  || echo "  (none)"

  echo "- Static backends (missing vs BASE):"
  [[ -s "$BASE_BE" ]] && comm -23 "$BASE_BE" "$CUR_BE" | sed 's/^/  MISSING: /' || echo "  (BASE has none)"
  echo "- Static backends (extra vs BASE):"
  [[ -s "$CUR_BE" ]]  && comm -13 "$BASE_BE" "$CUR_BE" | sed 's/^/  EXTRA:   /'  || echo "  (none)"

  echo "- Loaded dynamic modules (all cn=module{N}):"
  [[ -s "$WORKDIR/$h.modules.txt" ]] && awk '{print "  LOADED:  " $0}' "$WORKDIR/$h.modules.txt" || echo "  (none or not accessible)"
  echo
done

log "Done."

