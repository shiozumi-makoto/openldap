<?php
declare(strict_types=1);

namespace Tools\Ldap;

final class Uri
{
    /** ldapi パス → URI 変換（既定: /usr/local/var/run/ldapi） */
    public static function ldapi(?string $path=null): string
    {
        $p = $path ?: '/usr/local/var/run/ldapi';
        return 'ldapi://' . rawurlencode($p);
    }

    /** ldaps ホスト[:ポート] → URI 変換（既定: localhost:636） */
    public static function ldaps(?string $hostport=null): string
    {
        $hp = $hostport ?: 'localhost:636';
        if (strpos($hp, ':') === false) $hp .= ':636';
        return 'ldaps://' . $hp;
    }
}


