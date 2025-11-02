<?php
declare(strict_types=1);

/**
 * inc/debug_info.inc.php
 *
 * CLIツール共通のデバッグ情報出力。
 * 
 * 使い方:
 *   require __DIR__ . '/debug_info.inc.php';
 *   DebugInfo::print($vars);
 */

final class DebugInfo
{
    /**
     * @param array<string,mixed> $vars  デバッグ表示したい変数の配列
     */
    public static function print(array $vars): void
    {
        echo PHP_EOL;
        echo " ----------------------------------------------------------- debug info " . PHP_EOL;
        foreach ($vars as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            echo sprintf(" %-13s => %s\n", $k, (string)$v);
        }
        echo " ---------------------------------------------------------------------- " . PHP_EOL . PHP_EOL;
    }
}


