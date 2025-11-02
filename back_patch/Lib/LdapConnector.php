<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * LdapConnector.php
 *  - ldapi/ldaps/ldap(+StartTLS) を吸収し、SASL/EXTERNAL も自動試行
 *  - Config::load() / loadWithFile() 済みの $cfg を受ける
 */
final class LdapConnector
{
    /**
     * @param array<string,mixed> $cfg
     * @param null|callable(string):void $dbg
     * @return array{0:\LDAP\Connection,1:?string,2:?string,3:string} [$ds, $baseDn, $groupsDn, $uri]
     */
    public static function connect(array $cfg, ?callable $dbg = null): array
    {
        $dbg ??= static function (string $m): void {};

        // URI
        $uri = (string)($cfg['uri'] ?? '');
        if ($uri === '') {
            if (!empty($cfg['ldapi'])) {
                $uri = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
            } else {
                $scheme = !empty($cfg['ldaps']) ? 'ldaps' : 'ldap';
                $host   = (string)($cfg['host'] ?? '127.0.0.1');
                $port   = (int)($cfg['port'] ?? (!empty($cfg['ldaps']) ? 636 : 389));
                $uri    = sprintf('%s://%s:%d', $scheme, $host, $port);
            }
        }
        $dbg("LDAP URI={$uri}");

        // 接続
        $ds = @ldap_connect($uri);
        if (!$ds) {
            throw new \RuntimeException("ldap_connect 失敗: {$uri}");
        }
        ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ds, LDAP_OPT_TIMELIMIT, (int)($cfg['timeout'] ?? 10));

        // StartTLS
        if (!empty($cfg['starttls'])) {
            if (!@ldap_start_tls($ds)) {
                throw new \RuntimeException('StartTLS に失敗しました: ' . ldap_error($ds));
            }
            $dbg('StartTLS: OK');
        }

        // Bind
        $bindDn   = (string)($cfg['bind_dn']   ?? '');
        $bindPass = (string)($cfg['bind_pass'] ?? '');

        if ($bindDn !== '') {
            $ok = @ldap_bind($ds, $bindDn, $bindPass);
            if (!$ok) {
                throw new \RuntimeException('Simple Bind 失敗: ' . ldap_error($ds));
            }
            $dbg('Bind(Simple): OK');
        } else {
            if (str_starts_with($uri, 'ldapi://') && function_exists('ldap_sasl_bind')) {
                $ok = @ldap_sasl_bind($ds, null, null, 'EXTERNAL');
                if (!$ok) {
                    throw new \RuntimeException('SASL/EXTERNAL 失敗: ' . ldap_error($ds));
                }
                $dbg('Bind(SASL/EXTERNAL): OK');
            } else {
                @ldap_bind($ds); // 匿名
                $dbg('Bind(anonymous): OK');
            }
        }

        return [$ds, $cfg['base_dn'] ?? null, $cfg['groups_dn'] ?? null, $uri];
    }
}

