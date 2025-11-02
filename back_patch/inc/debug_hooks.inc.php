<?php
declare(strict_types=1);

/**
 * inc/debug_hooks.inc.php
 * 
 * CLIツール共通: --confirm / --inc などのデバッグオプション対応
 *
 * 使用例:
 *   require __DIR__ . '/debug_hooks.inc.php';
 *   $opt = CliUtil::args($argv);
 *   [$confirm, $wantInc] = DebugHooks::init($opt);
 */

final class DebugHooks
{
    public static bool $confirm = false;
    public static bool $wantInc = false;

    public static function init(array $opt): array
    {
        self::$confirm = !empty($opt['confirm']);
        self::$wantInc = !empty($opt['inc']);

        if (self::$wantInc) {
            register_shutdown_function(function (): void {
                echo PHP_EOL . "--- Included files -------------------------" . PHP_EOL;
                foreach (get_included_files() as $f) {
                    echo "  $f" . PHP_EOL;
                }
                echo "--------------------------------------------" . PHP_EOL;
            });
        }

        return [self::$confirm, self::$wantInc];
    }
}


