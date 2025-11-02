<?php
namespace Tools\Ldap;

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
