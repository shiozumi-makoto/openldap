#!/usr/bin/env bash
set -Eeuo pipefail

# This script injects the following line RIGHT AFTER the opening <?php in each target file:
#   require __DIR__ . '/ldap_cli_uri_switch.inc.php';
#
# It is idempotent (won't duplicate the line if already present).

REQ="require __DIR__ . '/ldap_cli_uri_switch.inc.php';"
TARGETS=(
  "ldap_memberuid_users_group.php"
  "ldap_groupmap_smb_add.php"
  "ldap_prune_home_dirs.php"
)

for f in "${TARGETS[@]}"; do
  if [[ ! -f "$f" ]]; then
    echo "[WARN] skip: $f not found in $(pwd)"
    continue
  fi

  if grep -qF "$REQ" "$f"; then
    echo "[OK] already patched: $f"
    continue
  fi

  # Insert after the first line containing <?php
  # We try to preserve original line endings.
  tmp="$(mktemp)"
  awk -v req="$REQ" '
    BEGIN { injected=0 }
    {
      print $0
      if (!injected && $0 ~ /<\?php[[:space:]]*$/) {
        print req
        injected=1
      }
    }
    END {
      if (!injected) {
        # If <?php not found, prepend at top
        print req > "/dev/stderr"
      }
    }
  ' "$f" > "$tmp"

  # If <?php was missing, we prepend
  if ! grep -q '<\?php' "$f"; then
    printf "<?php\n%s\n" "$REQ" | cat - "$f" > "$tmp"
  fi

  mv "$tmp" "$f"
  echo "[DONE] patched: $f"
done

echo "All done."
