<?php
declare(strict_types=1);

(function (): void {
    // 0) Composer 併用時は先に読む（存在すれば）
    foreach ([
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ] as $composer) {
        if (is_file($composer)) {
            require_once $composer;
            break;
        }
    }

    // 1) PHP 7.x 向け str_starts_with ポリフィル（PHP8仕様に準拠）
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            // 空needleは true
            if ($needle === '') return true;
            return strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    // 2) ロガー（見つからなかった時、一度だけ通知）
    static $warned = false;
    $logNotFound = static function (string $class) use (&$warned): void {
        if ($warned) return;
        $warned = true;
        error_log("[autoload] class not found: {$class}");
    };

    // 3) APCu キャッシュ有効なら使う（CLIで有効にするには apc.enable_cli=1 が必要）
    $apcuEnabled = function_exists('apcu_fetch') && ini_get('apc.enabled');

    // 4) PSR-4 風マッピング
    spl_autoload_register(static function (string $class) use ($logNotFound, $apcuEnabled): void {
        // APCu に保存済みなら即読込
        if ($apcuEnabled) {
            $key = 'tools_autoload:' . $class;
            $cached = apcu_fetch($key, $ok);
            if ($ok && is_string($cached) && is_file($cached)) {
                require_once $cached;
                return;
            }
        }

        $map = [
            'Tools\\Ldap\\' => __DIR__ . '/Ldap/',
            'Tools\\Lib\\'  => __DIR__ . '/Lib/',
            'Tools\\Support\\'  => __DIR__ . '/Support/',
            // ※ Tools\Ldap\Support\... は上の 'Tools\Ldap\' マップで /Ldap/Support/... に解決されます。
        ];

        foreach ($map as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) continue;

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            // ディレクトリトラバーサル防止
            $realBase = realpath($baseDir) ?: $baseDir;
            $realFile = realpath($file);
            if ($realFile !== false && strpos($realFile, $realBase) !== 0) {
                $logNotFound($class);
                return;
            }

            if (is_file($file)) {
                require_once $file;
                if ($apcuEnabled) apcu_store($key, $file, 300); // 5分キャッシュ
                return;
            }
        }

        // ここに来るのはマッピング外 or ファイルなし
        $logNotFound($class);
    });
})();
