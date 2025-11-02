<?php
declare(strict_types=1);

namespace Tools\Ldap\Support;

/**
 * LdapUtil
 *  - 依存の少ない軽量ヘルパ（ldapi/ldaps/ldap に対応）
 *  - 必要最小限: connect(), readEntries()
 *
 * 使い方:
 *   $ds = LdapUtil::connect('ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi');
 *   $rows = LdapUtil::readEntries($ds, 'ou=Users,dc=example,dc=com', '(uid=*)', ['uid','cn']);
 */
final class LdapUtil
{
    /**
     * LDAP 接続を作成
     * - ldapi:// なら SASL/EXTERNAL（root 実行を想定）
     * - ldaps:// / ldap:// は匿名 bind（必要なら外側で Simple bind を実行してください）
     */
    public static function connect(string $uri)
    {
        $ds = @ldap_connect($uri);
        if (!$ds) {
            throw new \RuntimeException("ldap_connect 失敗: {$uri}");
        }

        // 推奨オプション
        @ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 5);

        // ldapi は SASL/EXTERNAL
        if (str_starts_with($uri, 'ldapi://')) {
            // root で実行している前提。匿名 bind でも検索だけは可能な環境もあるが、
            // ここでは EXTERNAL を試み、失敗したら匿名にフォールバック
            if (!@ldap_sasl_bind($ds, null, null, 'EXTERNAL')) {
                @ldap_bind($ds); // フォールバック: anonymous
            }
        } else {
            // 通常は匿名 bind（必要なら外で bind し直す）
            @ldap_bind($ds);
        }

        return $ds;
    }

    /**
     * 単純に検索して配列の配列で返す
     * @param resource|\LDAP\Connection $ds
     * @param string $base
     * @param string $filter
     * @param array<int,string> $attrs
     * @return array<int,array<string,array<int|string,string>>>
     */
    public static function readEntries($ds, string $base, string $filter, array $attrs = []): array
    {
        $res = @ldap_search($ds, $base, $filter, $attrs);
        if (!$res) return [];

        $out = [];
        $entry = @ldap_first_entry($ds, $res);
        while ($entry) {
            $a = @ldap_get_attributes($ds, $entry);
            if (is_array($a)) {
                // ldap_get_attributes は "count" や数値キーを含むので整形
                $row = [];
                foreach ($a as $k => $v) {
                    if (is_string($k) && is_array($v) && isset($v['count'])) {
                        $vals = [];
                        for ($i = 0; $i < (int)$v['count']; $i++) {
                            $vals[] = (string)$v[$i];
                        }
                        $row[$k] = $vals;
                    }
                }
                $out[] = $row;
            }
            $entry = @ldap_next_entry($ds, $entry);
        }
        return $out;
    }
}
