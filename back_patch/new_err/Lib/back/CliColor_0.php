<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * シンプルな CLI カラー出力ユーティリティ（最終版）
 *
 * 特徴:
 * - TTY出力時は自動でカラー有効。
 * - パイプ/teeなどTTYでない場合も、環境変数で強制可。
 *   環境変数:
 *     CLICOLOR_FORCE=1 または FORCE_COLOR=1  → 強制オン
 *     NO_COLOR が存在                        → 強制オフ
 *
 * - 基本メソッド: bold, red, yellow, green, cyan, boldRed, boldYellow, boldGreen, boldCyan
 * - println() / printErr() で出力（自動で改行つき）
 * - strip() でANSIコードを除去可能。
 */
final class CliColor
{
    /** ANSIカラー使用可否を判定 */
    private static function on(): bool
    {
        if (PHP_SAPI !== 'cli') return false;

        // ---- 環境変数による明示的な制御 ----
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (($f = getenv('CLICOLOR_FORCE')) && $f !== '0') {
            return true;
        }
        if (($f = getenv('FORCE_COLOR')) && $f !== '0') {
            return true;
        }

        // ---- TTY 判定 ----
        if (function_exists('posix_isatty')) {
            $fd = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
            return @posix_isatty($fd);
        }

        // posix_isatty がない環境では CLICOLOR=1 のとき有効化
        return (getenv('CLICOLOR') && getenv('CLICOLOR') !== '0');
    }

    /** ANSIコードラッパ */
    private static function wrap(string $s, string $code): string
    {
        if ($s === '' || !self::on()) {
            return $s;
        }
        return "\033[" . $code . "m" . $s . "\033[0m";
    }

    // ===== 基本スタイル =====
    public static function bold(string $s): string      { return self::wrap($s, '1'); }
    public static function red(string $s): string       { return self::wrap($s, '31'); }
    public static function yellow(string $s): string    { return self::wrap($s, '33'); }
    public static function green(string $s): string     { return self::wrap($s, '32'); }
    public static function cyan(string $s): string      { return self::wrap($s, '36'); }
	public static function blue(string $s): string     { return self::wrap($s, '34'); }

    // ===== 太字＋色 =====
    public static function boldRed(string $s): string    { return self::wrap($s, '1;31'); }
    public static function boldYellow(string $s): string { return self::wrap($s, '1;33'); }
    public static function boldGreen(string $s): string  { return self::wrap($s, '1;32'); }
    public static function boldCyan(string $s): string   { return self::wrap($s, '1;36'); }
public static function boldBlue(string $s): string { return self::wrap($s, '1;34'); }

    // ===== 出力ヘルパ =====
    /** 改行つき標準出力（色付きテキスト対応） */
    public static function println(string $text): void
    {
        echo $text . PHP_EOL;
    }

    /** 改行つき標準エラー出力（赤で統一） */
    public static function printErr(string $text): void
    {
        fwrite(STDERR, self::red($text) . PHP_EOL);
    }

    // ===== おまけ =====
    /** 色コードを除去（ログ保存時など） */
    public static function strip(string $s): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? $s;
    }
}
