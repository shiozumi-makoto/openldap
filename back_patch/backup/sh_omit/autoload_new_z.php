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
 * - APCu によるクラス→ファイルパスの簡易キャッシュ（任意）
 * - 見つからない場合に error_log へ一行だけ通知（AUTOLOAD_DEBUG=1 で詳細）
 * - 互換: Tools\Ldap\CliColor / CliUtil 要求時に Tools\Lib\ 側を読み込んで class_alias
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

    // 1) PHP 7.x 向けの簡易ポリフィル
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    // 2) ロガー（見つからなかった時、一度だけ通知 / DEBUG=詳細）
    $DEBUG = getenv('AUTOLOAD_DEBUG') === '1';
    static $warned = false;
    $logNotFound = static function (string $class) use (&$warned, $DEBUG): void {
        if ($DEBUG) {
            error_log("[autoload] not found: {$class}");
            return;
        }
        if ($warned) return;
        $warned = true;
        error_log("[autoload] class not found: {$class}");
    };
    $log = static function (string $msg) use ($DEBUG): void {
        if ($DEBUG) error_log("[autoload] {$msg}");
    };

    // 3) APCu キャッシュ有効なら使う
    $apcuEnabled = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);

    // 4) PSR-4 マップ
    $map = [
        'Tools\\Ldap\\' => __DIR__ . '/Ldap/',
        'Tools\\Lib\\'  => __DIR__ . '/Lib/',
    ];

    // 5) 互換のための特例（Ldap 名で要求されたら Lib 実体を alias）
    $compatAliases = [
        'Tools\\Ldap\\CliColor' => ['fqcn' => 'Tools\\Lib\\CliColor', 'file' => __DIR__ . '/Lib/CliColor.php'],
        'Tools\\Ldap\\CliUtil'  => ['fqcn' => 'Tools\\Lib\\CliUtil',  'file' => __DIR__ . '/Lib/CliUtil.php'],
    ];

    spl_autoload_register(static function (string $class) use ($map, $compatAliases, $logNotFound, $log, $apcuEnabled): void {
        // 5-a) 互換: Ldap\CliColor / CliUtil → Lib 側の実体を alias
        if (isset($compatAliases[$class])) {
            $target = $compatAliases[$class]['fqcn'];
            $file   = $compatAliases[$class]['file'];

            // まず Lib 側の実体を読み込み（なければ静かにスキップ）
            if (!class_exists($target, false) && is_file($file)) {
                require_once $file;
                $log("compat require: {$file}");
            }
            // 実体があれば、要求クラス名へ alias（既に存在していれば何もしない）
            if (class_exists($target, false) && !class_exists($class, false)) {
                class_alias($target, $class);
                $log("class_alias: {$target} as {$class}");
                return;
            }
            // 実体が無い場合は以降の通常解決へ（最終的に見つからなければ静音ログ）
        }

        // 4-a) APCu キャッシュがあれば使用
        $apcuKey = 'tools_autoload:' . $class;
        if ($apcuEnabled) {
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_string($cached) && is_file($cached)) {
                require_once $cached;
                $log("apcu hit: {$class} -> {$cached}");
                return;
            }
        }

        // 4-b) 通常の PSR-4 解決
        foreach ($map as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) continue;

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            // ディレクトリトラバーサル防止（念のため）
            $realBase = realpath($baseDir) ?: $baseDir;
            $realFile = realpath($file);
            if ($realFile !== false && strpos($realFile, $realBase) !== 0) {
                $log("blocked (outside base): {$realFile}");
                $logNotFound($class);
                return;
            }

            if (is_file($file)) {
                require_once $file;
                if ($apcuEnabled) apcu_store($apcuKey, $file, 300); // 5分キャッシュ
                $log("require: {$class} -> {$file}");
                return;
            }

            // この prefix で見つからなくても、他の prefix を試すので continue せずループ継続
        }

        // ここに来たらマッピング外 or ファイルなし
        $logNotFound($class);
    }, true, true);
})();

