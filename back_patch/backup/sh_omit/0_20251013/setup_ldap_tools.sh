#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="/usr/local/etc/openldap/tools"
LDAP_DIR="$BASE_DIR/Ldap"

echo "[*] Creating directories..."
sudo mkdir -p "$LDAP_DIR"

timestamp="$(date +%Y%m%d-%H%M%S)"

backup_if_exists () {
  local path="$1"
  if [ -e "$path" ]; then
    sudo mv "$path" "${path}.bak.${timestamp}"
    echo "    - backed up: $path -> ${path}.bak.${timestamp}"
  fi
}

# ---------- autoload.php ----------
backup_if_exists "$BASE_DIR/autoload.php"
sudo tee "$BASE_DIR/autoload.php" >/dev/null <<'PHP'
<?php
/**
 * PSR-4風の簡易オートローダ
 * ベース: /usr/local/etc/openldap/tools/Ldap/
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Ldap\\';
    $baseDir = __DIR__ . '/Ldap/';

    if (!str_starts_with($class, $prefix)) return;

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
PHP

# ---------- Ldap/Env.php ----------
backup_if_exists "$LDAP_DIR/Env.php"
sudo tee "$LDAP_DIR/Env.php" >/dev/null <<'PHP'
<?php
namespace Ldap;

final class Env {
    public static function get(string $key, ?string $alt = null, ?string $def = null): ?string {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if ($alt) {
            $v = getenv($alt);
            if ($v !== false && $v !== '') return $v;
        }
        return $def;
    }

    public static function first(array $keys, ?string $def = null): ?string {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') return $v;
        }
        return $def;
    }

    public static function showOrMask(?string $s): string {
        return ($s === null || preg_match('/^\s*$/u', (string)$s))
            ? '[*** 未設定 ***]'
            : $s;
    }
}
PHP

# ---------- Ldap/Connection.php ----------
backup_if_exists "$LDAP_DIR/Connection.php"
sudo tee "$LDAP_DIR/Connection.php" >/dev/null <<'PHP'
<?php
namespace Ldap;

use RuntimeException;

final class Connection {
    public static function connect(): \LDAP\Connection {
        $uri = Env::first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
        if (!$uri) throw new RuntimeException('LDAP_URI not set');

        // ldapi:/// の緩やかな正規化
        if ($uri === 'ldapi:///') {
            $uri = 'ldapi://%2Fvar%2Frun%2Fldapi';
        }

        $ds = @ldap_connect($uri);
        if (!$ds) throw new RuntimeException("ldap_connect failed: {$uri}");

        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

        if (str_starts_with($uri, 'ldapi://')) {
            if (!@ldap_sasl_bind($ds, null, null, 'EXTERNAL'))
                throw new RuntimeException('SASL EXTERNAL failed: ' . ldap_error($ds));
            return $ds; // EXTERNAL済み
        }

        if (str_starts_with($uri, 'ldap://') && !@ldap_start_tls($ds)) {
            throw new RuntimeException('StartTLS failed: ' . ldap_error($ds));
        }

        return $ds;
    }

    public static function bind(\LDAP\Connection $ds): void {
        $uri = Env::first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
        if ($uri && str_starts_with($uri, 'ldapi://')) return; // EXTERNAL済み

        $dn = Env::get('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
        $pw = Env::get('BIND_PW', 'LDAP_ADMIN_PW', '');
        if ($dn === '' || $pw === '') {
            throw new RuntimeException('BIND_DN/BIND_PW required for non-ldapi connections.');
        }
        if (!@ldap_bind($ds, $dn, $pw)) {
            throw new RuntimeException('simple bind failed: ' . ldap_error($ds));
        }
    }

    public static function close(?\LDAP\Connection $ds): void {
        if ($ds) @ldap_unbind($ds);
    }
}
PHP

# ---------- Ldap/MemberUid.php ----------
backup_if_exists "$LDAP_DIR/MemberUid.php"
sudo tee "$LDAP_DIR/MemberUid.php" >/dev/null <<'PHP'
<?php
namespace Ldap;

use RuntimeException;

final class MemberUid {
    /** LDAP から全ユーザー取得（uid, dn, gidNumber 等） */
    public static function fetchUsers(\LDAP\Connection $ds, string $peopleDn): array {
        $attrs = ['uid','gidNumber','uidNumber','cn'];
        $sr = @ldap_search($ds, $peopleDn, '(uid=*)', $attrs);
        if ($sr === false) throw new RuntimeException('search users failed: ' . ldap_error($ds));
        $entries = ldap_get_entries($ds, $sr);
        $users = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $e = $entries[$i];
            if (empty($e['uid'][0]) || empty($e['dn'])) continue;
            $users[] = [
                'dn'        => $e['dn'],
                'uid'       => (string)$e['uid'][0],
                'gidNumber' => $e['gidnumber'][0] ?? null,
                'uidNumber' => $e['uidnumber'][0] ?? null,
                'cn'        => $e['cn'][0] ?? '',
            ];
        }
        return $users;
    }

    /** グループの memberUid 現状取得 */
    public static function fetchGroupMemberUids(\LDAP\Connection $ds, string $groupDn): array {
        $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid','cn']);
        if ($sr === false) throw new RuntimeException('group read failed: ' . ldap_error($ds));
        $e = ldap_get_entries($ds, $sr);
        if (($e['count'] ?? 0) < 1) throw new RuntimeException("group not found: {$groupDn}");
        $arr = [];
        if (!empty($e[0]['memberuid'])) {
            for ($i = 0; $i < $e[0]['memberuid']['count']; $i++) {
                $arr[] = (string)$e[0]['memberuid'][$i];
            }
        }
        return $arr;
    }

    /** memberUid を追加（既存ならスキップ） */
    public static function add(\LDAP\Connection $ds, string $groupDn, string $uid, bool $doWrite): bool {
        $current = self::fetchGroupMemberUids($ds, $groupDn);
        if (in_array($uid, $current, true)) return false;
        if (!$doWrite) return true; // ドライラン

        $entry = ['memberUid' => $uid];
        if (!@ldap_mod_add($ds, $groupDn, $entry)) {
            $err = ldap_error($ds);
            if (stripos($err, 'Type or value exists') !== false) return false;
            throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
        }
        return true;
    }
}
PHP

# ---------- Ldap/CliColor.php ----------
backup_if_exists "$LDAP_DIR/CliColor.php"
sudo tee "$LDAP_DIR/CliColor.php" >/dev/null <<'PHP'
<?php
namespace Ldap;

final class CliColor {
    public static function enabled(): bool {
        if (PHP_SAPI !== 'cli') return false;
        if (getenv('NO_COLOR')) return false;
        if (getenv('FORCE_COLOR')) return true;
        return true; // デフォルトON（必要ならNO_COLORで無効化）
    }

    public static function ansi(string $text, string $style): string {
        return self::enabled() ? "\033[{$style}m{$text}\033[0m" : $text;
    }

    public static function boldRed(string $t): string { return self::ansi($t, '1;31'); }
    public static function red(string $t): string     { return self::ansi($t, '31'); }
    public static function yellow(string $t): string  { return self::ansi($t, '33'); }
    public static function dim(string $t): string     { return self::ansi($t, '2'); }
}
PHP

echo "[*] Done."
echo "    Autoloader:   $BASE_DIR/autoload.php"
echo "    Namespaces:   Ldap\\Env, Ldap\\Connection, Ldap\\MemberUid, Ldap\\CliColor"

