<?php
declare(strict_types=1);

/**
 * inc/ldap_cli_uri_switch.inc.php
 * 
 * CLIツール共通：--ldapi / --ldaps / --ldap=<uri> の切替と接続確認。
 *
 * 使用例:
 *   require __DIR__ . '/ldap_cli_uri_switch.inc.php';
 *   // 環境変数 LDAPURI / LDAP_URI に設定される
 *
 * 追加オプション:
 *   --ldapi[=/path/to/ldapi]
 *   --ldaps[=host[:port]]
 *   --ldap=<full-uri>
 *   --host= / --port=（ldaps時補助）
 *
 * 表示:
 *   VERBOSE=1 または --confirm が指定されている場合、接続サマリーを表示。
 */

(function () {
    if (PHP_SAPI !== 'cli') return;

    $argv0 = $_SERVER['argv'] ?? [];
    $argc0 = (int)($_SERVER['argc'] ?? 0);
    if ($argc0 <= 1) return;

    $chosen = null;
    $mode   = null;
    $host   = null;
    $port   = null;
    $sock   = null;
    $keep   = [];

    $DEFAULT_LDAPI_SOCK = '/usr/local/var/run/ldapi';
    $DEFAULT_LDAPS_HOST = '127.0.0.1';
    $DEFAULT_LDAPS_PORT = 636;

    $kv = static function (string $s): array {
        $p = strpos($s, '=');
        if ($p === false) return [$s, null];
        return [substr($s, 0, $p), substr($s, $p + 1)];
    };

    foreach ($argv0 as $i => $a) {
        if ($i === 0) { $keep[] = $a; continue; }

        if (stripos($a, '--ldap=') === 0) {
            [, $val] = $kv($a);
            if ($val) {
                $chosen = $val;
                $mode   = 'ldap';
            }
            continue;
        }

        if (stripos($a, '--ldapi') === 0) {
            $val = null;
            if (strpos($a, '=') !== false) [, $val] = $kv($a);
            $sock = ($val && $val !== '') ? $val : $DEFAULT_LDAPI_SOCK;
            $chosen = 'ldapi://' . rawurlencode($sock);
            $mode   = 'ldapi';
            continue;
        }

        if (stripos($a, '--ldaps') === 0) {
            $val = null;
            if (strpos($a, '=') !== false) [, $val] = $kv($a);
            if ($val && $val !== '') {
                if (strpos($val, ':') !== false) {
                    [$host, $p] = explode(':', $val, 2);
                    $port = ctype_digit($p) ? (int)$p : $DEFAULT_LDAPS_PORT;
                } else {
                    $host = $val;
                }
            }
            $mode = 'ldaps';
            continue;
        }

        if (stripos($a, '--host=') === 0) {
            [, $host] = $kv($a);
            continue;
        }
        if (stripos($a, '--port=') === 0) {
            [, $p] = $kv($a);
            if ($p && ctype_digit($p)) $port = (int)$p;
            continue;
        }

        $keep[] = $a;
    }

    if ($chosen === null && $mode === 'ldaps') {
        $h = $host ?: $DEFAULT_LDAPS_HOST;
        $p = $port ?: $DEFAULT_LDAPS_PORT;
        $chosen = sprintf('ldaps://%s:%d', $h, $p);
    }

    if ($chosen !== null) {
        putenv("LDAPURI={$chosen}");
        putenv("LDAP_URI={$chosen}");
        $_ENV['LDAPURI'] = $chosen;
        $_ENV['LDAP_URI'] = $chosen;

        $_SERVER['argv'] = $keep;
        $_SERVER['argc'] = count($keep);
        $GLOBALS['argv'] = $keep;
        $GLOBALS['argc'] = count($keep);

        // === 接続サマリー ===
        $showSummary = (getenv('VERBOSE') === '1' || getenv('VERBOSE') === 'true');
        foreach ($keep as $arg) {
            if ($arg === '--confirm') $showSummary = true;
        }

        if ($showSummary) {
            fwrite(STDERR, PHP_EOL);
            fwrite(STDERR, "=== LDAP connection summary ===" . PHP_EOL);
            fwrite(STDERR, " Mode      : {$mode}" . PHP_EOL);
            fwrite(STDERR, " URI       : {$chosen}" . PHP_EOL);

            $conn = @ldap_connect($chosen);
            if ($conn === false) {
                fwrite(STDERR, " Status    : ? connection failed" . PHP_EOL);
            } else {
                ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
                $ok = @ldap_bind($conn);
                if ($ok) {
                    fwrite(STDERR, " Status    : ? bind success (anonymous)" . PHP_EOL);
                } else {
                    $err = ldap_error($conn);
                    fwrite(STDERR, " Status    : ?? bind failed - {$err}" . PHP_EOL);
                }
                $info = @ldap_get_option($conn, LDAP_OPT_PROTOCOL_VERSION, $ver) ? $ver : '(n/a)';
                fwrite(STDERR, " Protocol  : v{$info}" . PHP_EOL);
                @ldap_unbind($conn);
            }
            fwrite(STDERR, "===============================" . PHP_EOL . PHP_EOL);
        }
    }
})();


