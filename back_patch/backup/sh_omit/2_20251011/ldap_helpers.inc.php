<?php
declare(strict_types=1);

/**
 * ldap_helpers.inc.php
 * -------------------------------------------
 * - すべて「include」前提で安全に再読み込みできるよう function_exists ガードを併用
 * - 環境変数の優先探索: LDAP_URL / LDAP_URI / LDAPURI
 * - ldapi:// は SASL EXTERNAL（root実行前提）
 * - ldap:// は StartTLS を必須（失敗時は例外）
 * - ldaps:// はそのまま
 * - BIND_DN / BIND_PW（または LDAP_ADMIN_PW）で simple bind
 * - 便利関数:
 *     - ldap_connect_secure_and_bind()
 *     - ldap_close_safely()
 *     - assert_ldap_ext_loaded()
 *     - ldap_apply_recommended_options()
 *     - ldap_connect_with_fallback()
 *     - with_ldap()
 *     - ldap_retry()
 * - ログ補助:
 *     - log_line() / log_line_silent()
 */

/* ========== ENV UTILS ========== */

if (!function_exists('envv')) {
    /** まず $key を、なければ $alt を、それも空なら $def を返す */
    function envv(string $key, ?string $alt = null, ?string $def = null): ?string {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if ($alt) {
            $v = getenv($alt);
            if ($v !== false && $v !== '') return $v;
        }
        return $def;
    }
}

if (!function_exists('env_first')) {
    /** 複数キーのうち最初に見つかった非空を返す */
    function env_first(array $keys, ?string $def = null): ?string {
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') return $v;
        }
        return $def;
    }
}

if (!function_exists('show_or_mask')) {
    /** URI などを未設定時にマスク表示 */
    function show_or_mask(?string $uri): string {
        return ($uri === null || preg_match('/^\s*$/u', (string)$uri))
            ? '[*** 未設定 ***]'
            : $uri;
    }
}

/* ========== URI HELPERS ========== */

if (!function_exists('is_ldapi')) {
    function is_ldapi(string $uri): bool { return str_starts_with($uri, 'ldapi://'); }
}
if (!function_exists('is_ldaps')) {
    function is_ldaps(string $uri): bool { return str_starts_with($uri, 'ldaps://'); }
}
if (!function_exists('is_ldap_plain')) {
    function is_ldap_plain(string $uri): bool { return str_starts_with($uri, 'ldap://'); }
}

if (!function_exists('get_ldap_uri')) {
    /** LDAP 接続先 URI を環境から決定（LDAP_URL > LDAP_URI > LDAPURI） */
    function get_ldap_uri(): ?string {
        return env_first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
    }
}

if (!function_exists('normalize_ldapi_uri')) {
    /** ldapi:/// → 標準パスに正規化（必要に応じて拡張） */
    function normalize_ldapi_uri(string $uri): string {
        if (!is_ldapi($uri)) return $uri;
        if ($uri === 'ldapi:///') {
            // CentOS/RHEL 例：/var/run/ldapi
            return 'ldapi://%2Fvar%2Frun%2Fldapi';
        }
        return $uri;
    }
}

/* ========== CONNECT & BIND ========== */

if (!function_exists('ldap_connect_secure')) {
    /**
     * secure な LDAP Connection を返す（bind はしない）
     * - ldapi:// → SASL EXTERNAL
     * - ldap://  → StartTLS 必須
     * - ldaps:// → そのまま
     */
    function ldap_connect_secure(): \LDAP\Connection {
        $uri = get_ldap_uri();
        if ($uri === null || $uri === '') {
            throw new RuntimeException('LDAP URI is not set (LDAP_URL / LDAP_URI / LDAPURI).');
        }
        $uri = normalize_ldapi_uri($uri);

        $ds = @ldap_connect($uri);
        if (!$ds) throw new RuntimeException("ldap_connect failed: {$uri}");

        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

        if (is_ldapi($uri)) {
            if (!@ldap_sasl_bind($ds, null, null, 'EXTERNAL')) {
                throw new RuntimeException('SASL EXTERNAL bind failed: ' . ldap_error($ds));
            }
            return $ds;
        }

        if (is_ldap_plain($uri)) {
            if (!@ldap_start_tls($ds)) {
                throw new RuntimeException('StartTLS failed: ' . ldap_error($ds));
            }
        }
        return $ds;
    }
}

if (!function_exists('maybe_simple_bind')) {
    /** simple bind（ldapi/EXTERNAL のときは NOOP） */
    function maybe_simple_bind(\LDAP\Connection $ds): void {
        $uri = get_ldap_uri();
        if ($uri === null || $uri === '') {
            throw new RuntimeException('LDAP URI is not set.');
        }
        if (is_ldapi($uri)) return;

        $bindDn = envv('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
        $bindPw = envv('BIND_PW', 'LDAP_ADMIN_PW', '');

        if ($bindDn === '' || $bindPw === '') {
            throw new RuntimeException('BIND_DN/BIND_PW is required for non-ldapi connections.');
        }
        if (!@ldap_bind($ds, $bindDn, $bindPw)) {
            throw new RuntimeException('simple bind failed: ' . ldap_error($ds));
        }
    }
}

if (!function_exists('ldap_connect_secure_and_bind')) {
    /** 接続＋適切な bind（ldapi は EXTERNAL、他は simple bind） */
    function ldap_connect_secure_and_bind(): \LDAP\Connection {
        $ds = ldap_connect_secure();
        $uri = get_ldap_uri();
        if ($uri !== null && $uri !== '' && !is_ldapi($uri)) {
            maybe_simple_bind($ds);
        }
        return $ds;
    }
}

if (!function_exists('ldap_close_safely')) {
    /** 安全にクローズ（例外出さずに握り潰す） */
    function ldap_close_safely(?\LDAP\Connection $ds): void {
        if ($ds) @ldap_unbind($ds);
    }
}

/* ========== 追加ユーティリティ（任意だが便利） ========== */

if (!function_exists('assert_ldap_ext_loaded')) {
    function assert_ldap_ext_loaded(): void {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded.');
        }
    }
}

if (!function_exists('ldap_apply_recommended_options')) {
    function ldap_apply_recommended_options(\LDAP\Connection $ds): void {
        @ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 5);
        @ldap_set_option($ds, LDAP_OPT_TIMEOUT, 5);
        @ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
        // 必要に応じて外部で:
        // putenv('LDAPTLS_REQCERT=demand');
        // putenv('LDAPTLS_CACERT=/etc/pki/tls/certs/ca-bundle.crt');
    }
}

if (!function_exists('ldap_connect_with_fallback')) {
    /**
     * 指定URIがダメな場合に順次フォールバック。
     * - 既定: 現在の env URI → ldaps://hostHint → ldap://hostHint (StartTLS)
     */
    function ldap_connect_with_fallback(?string $hostHint = null): \LDAP\Connection {
        $tried = [];

        $uri = get_ldap_uri();
        if ($uri) {
            try {
                $ds = ldap_connect_secure();
                if (function_exists('ldap_apply_recommended_options')) {
                    ldap_apply_recommended_options($ds);
                }
                return $ds;
            } catch (Throwable $e) {
                $tried[] = $uri . ' (' . $e->getMessage() . ')';
            }
        }

        if ($hostHint) {
            foreach (["ldaps://{$hostHint}", "ldap://{$hostHint}"] as $alt) {
                try {
                    putenv("LDAP_URL={$alt}");
                    $ds = ldap_connect_secure();
                    if (function_exists('ldap_apply_recommended_options')) {
                        ldap_apply_recommended_options($ds);
                    }
                    return $ds;
                } catch (Throwable $e) {
                    $tried[] = $alt . ' (' . $e->getMessage() . ')';
                }
            }
        }
        throw new RuntimeException('All LDAP URIs failed: ' . implode(' ; ', $tried));
    }
}

if (!function_exists('with_ldap')) {
    /** 接続→処理→必ず unbind までを一括で */
    function with_ldap(Closure $fn, ?string $hostHint = null): void {
        $ds = null;
        try {
            $ds = ldap_connect_with_fallback($hostHint);
            $fn($ds);
        } finally {
            ldap_close_safely($ds);
        }
    }
}

if (!function_exists('ldap_retry')) {
    /** 操作をリトライ（競合や一時エラーの緩和に） */
    function ldap_retry(Closure $op, int $maxRetry = 2, int $sleepMs = 200): void {
        $last = null;
        for ($i = 0; $i <= $maxRetry; $i++) {
            try {
                $op();
                return;
            } catch (Throwable $e) {
                $last = $e;
                if ($i < $maxRetry) usleep($sleepMs * 1000);
            }
        }
        throw $last ?? new RuntimeException('ldap_retry failed without exception.');
    }
}

/* ========== ログ補助 ========== */

if (!isset($GLOBALS['LDAP_HELPERS_SILENT'])) {
    $GLOBALS['LDAP_HELPERS_SILENT'] = false; // true で静音化
}
if (!function_exists('log_line_silent')) {
    function log_line_silent(bool $silent): void { $GLOBALS['LDAP_HELPERS_SILENT'] = $silent; }
}
if (!function_exists('log_line')) {
    function log_line(string $msg): void {
        if (!empty($GLOBALS['LDAP_HELPERS_SILENT'])) return;
        fwrite(STDOUT, $msg . (str_ends_with($msg, "\n") ? '' : "\n"));
    }
}

if (!function_exists('mask_pw')) {
    function mask_pw(?string $pw): string { return ($pw!==null && $pw!=='') ? '********' : ''; }
}

/* ========== USAGE EXAMPLE (コメントアウト) ========== */
/*
putenv('LDAP_URL=ldapi:///'); // ローカル root 実行時（EXTERNAL）
# putenv('LDAP_URL=ldaps://ovs-012.e-smile.local');
# putenv('BIND_DN=cn=Admin,dc=e-smile,dc=ne,dc=jp');
# putenv('BIND_PW=********');

try {
    assert_ldap_ext_loaded();
    $ds = ldap_connect_secure_and_bind();
    echo "[OK] connected to ", show_or_mask(get_ldap_uri()), PHP_EOL;
    // ... ここで ldap_* 操作 ...
} catch (Throwable $e) {
    fwrite(STDERR, "[ERR] " . $e->getMessage() . PHP_EOL);
} finally {
    if (isset($ds)) ldap_close_safely($ds);
}
*/

