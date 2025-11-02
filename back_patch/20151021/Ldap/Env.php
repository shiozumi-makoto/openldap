<?php
namespace Tools\Ldap;

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
