<?php
declare(strict_types=1);

/**
 * PSR-4 風 簡易オートローダ（/usr/local/etc/openldap/tools 用）
 *
 * マッピング:
 *   Tools\Ldap\* → {THIS_DIR}/Ldap/
 *   Tools\Lib\*  → {THIS_DIR}/Lib/
 *
 * 追加要素:
 * - PHP 8 未満向けの str_starts_with ポリフィル
 * - vendor/autoload.php があれば先に読む（Composer 併用可）
 * - APCu によるクラス→ファイルパスの簡易キャッシュ（任意・無害）
 * - 見つからない場合に error_log へ一行だけ通知（多重発火抑止）
 */

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

    // 1) PHP 7.x 対応の簡易ポリフィル
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    // 2) ロガー（見つからなかった時、一度だけ通知）
    static $warned = false;
    $logNotFound = static function (string $class) use (&$warned): void {
        if ($warned) return;
        $warned = true;
        // noisy にならないよう1回だけ
        error_log("[autoload] class not found: {$class}");
    };

    // 3) APCu キャッシュ有効なら使う
    $apcuEnabled = function_exists('apcu_fetch') && ini_get('apc.enabled');

    // 4) 実装
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
        ];

        foreach ($map as $prefix => $baseDir) {
            if (str_starts_with($class, $prefix)) {
                $relative = substr($class, strlen($prefix));
                $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

                // ディレクトリトラバーサル防止（念のため）
                $realBase = realpath($baseDir) ?: $baseDir;
                $realFile = realpath($file);
                if ($realFile !== false && strpos($realFile, $realBase) !== 0) {
                    // ベース外は読み込まない
                    $logNotFound($class);
                    return;
                }

                if (is_file($file)) {
                    require_once $file;
                    if ($apcuEnabled) apcu_store($key, $file, 300); // 5分キャッシュ
                    return;
                }
            }
        }

        // ここに来るのはマッピング外 or ファイルなし
        $logNotFound($class);
    });
})();

