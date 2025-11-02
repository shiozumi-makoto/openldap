<?php
/**
 * PSR-4風 簡易オートローダ
 * ベース: /usr/local/etc/openldap/tools/{Ldap,Lib}/
 *
 * 対応:
 *   Tools\Ldap\* → tools/Ldap/
 *   Tools\Lib\*  → tools/Lib/
 */

spl_autoload_register(function (string $class): void {
    $map = [
        'Tools\\Ldap\\' => __DIR__ . '/Ldap/',
        'Tools\\Lib\\'  => __DIR__ . '/Lib/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});
