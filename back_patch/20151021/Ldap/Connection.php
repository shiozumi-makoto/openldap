<?php
declare(strict_types=1);

namespace Tools\Ldap;

use RuntimeException;

final class Connection
{
    /** init() で設定された既定URI（nullなら未設定） */
    private static ?string $defaultUri = null;

    /**
     * 初期化：以後の connect() の既定URIをセットします。
     * 引数が null の場合は環境変数から取得します（LDAP_URL / LDAP_URI / LDAPURI）。
     */
    public static function init(?string $uri = null): void
    {
        $uri = $uri ?? Env::first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
        if ($uri === null || $uri === '') {
            throw new RuntimeException('LDAP_URI not set (init)');
        }
        self::$defaultUri = self::normalizeUri($uri);
    }

    /**
     * 現在の既定URIを取得（init後）。未設定なら null。
     */
    public static function getDefaultUri(): ?string
    {
        return self::$defaultUri;
    }

    /**
     * 接続を確立して \LDAP\Connection を返します。
     * 優先順位：引数のURI > init()の既定URI > 環境変数（LDAP_URL/LDAP_URI/LDAPURI）
     */
    public static function connect(?string $uri = null): \LDAP\Connection
    {
        $uri = $uri
            ?? self::$defaultUri
            ?? Env::first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);

        if (!$uri) {
            throw new RuntimeException('LDAP_URI not set');
        }

        $uri = self::normalizeUri($uri);

        $ds = @ldap_connect($uri);
        if (!$ds) {
            throw new RuntimeException("ldap_connect failed: {$uri}");
        }

        // 基本オプション
        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

        // ldapi:// は SASL EXTERNAL で認証（bind() 不要ケース）
        if (self::isLdapi($uri)) {
            if (!@ldap_sasl_bind($ds, null, null, 'EXTERNAL')) {
                throw new RuntimeException('SASL EXTERNAL failed: ' . ldap_error($ds));
            }
            return $ds; // ここで返す＝EXTERNAL済み
        }

        // ldap:// は StartTLS（失敗時は例外）。ldaps:// は不要。
        if (self::isLdap($uri)) {
            // 必須運用を続ける（必要なら環境で無効化フラグも検討可）
            if (!@ldap_start_tls($ds)) {
                throw new RuntimeException('StartTLS failed: ' . ldap_error($ds));
            }
        }

        return $ds;
    }

    /**
     * Simple Bind を行います（ldapi:// の場合は何もしません）。
     * 引数未指定時は環境変数から取得：
     *   BIND_DN（既定: cn=Admin,dc=e-smile,dc=ne,dc=jp）
     *   BIND_PW / LDAP_ADMIN_PW
     */
    public static function bind(\LDAP\Connection $ds, ?string $dn = null, ?string $pw = null, ?string $uri = null): void
    {
        // URI が渡されない場合も、既定や環境から推定（ldapi判定のため）
        $uri = $uri
            ?? self::$defaultUri
            ?? Env::first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);

        if ($uri && self::isLdapi(self::normalizeUri($uri))) {
            return; // EXTERNAL 済み（connect() 内で実施）
        }

        $dn = $dn ?? Env::get('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
        $pw = $pw ?? Env::get('BIND_PW', 'LDAP_ADMIN_PW', '');

        if ($dn === '' || $pw === '') {
            throw new RuntimeException('BIND_DN/BIND_PW required for non-ldapi connections.');
        }
        if (!@ldap_bind($ds, $dn, $pw)) {
            throw new RuntimeException('simple bind failed: ' . ldap_error($ds));
        }
    }

    /**
     * 接続をクローズ（unbind）します。nullは無視。
     */
    public static function close(?\LDAP\Connection $ds): void
    {
        if ($ds) {
            @ldap_unbind($ds);
        }
    }

    /* ============================== helper ============================== */

    private static function normalizeUri(string $uri): string
    {
        // ldapi:/// の緩やかな正規化
        if ($uri === 'ldapi:///') {
            return 'ldapi://%2Fvar%2Frun%2Fldapi';
        }
        return $uri;
    }

    private static function isLdapi(string $uri): bool
    {
        return str_starts_with($uri, 'ldapi://');
    }

    private static function isLdaps(string $uri): bool
    {
        return str_starts_with($uri, 'ldaps://');
    }

    private static function isLdap(string $uri): bool
    {
        // 平文ldap（StartTLS対象）
        return str_starts_with($uri, 'ldap://');
    }
}
