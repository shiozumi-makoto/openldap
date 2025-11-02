<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * CliColor.php (PHP8 safe)
 *  - ANSIカラー出力ヘルパ
 *  - ネスト三項は禁止（switchで安全に）
 *  - 旧API(boldGreen(), boldCyan()等)互換も維持
 */
final class CliColor
{
    /** ANSIカラー有効判定（TTY判定＋環境変数） */
    private static function enabled(): bool
    {
        // 明示的にOFF指定されていなければ有効
        if (getenv('NO_COLOR')) return false;
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        return true;
    }

    /** 任意カラーで文字列を装飾 */
    public static function colorize(string $text, string $style): string
    {
        if (!self::enabled()) return $text;

        switch ($style) {
            case 'green':      return "\033[32m{$text}\033[0m";
            case 'yellow':     return "\033[33m{$text}\033[0m";
            case 'red':        return "\033[31m{$text}\033[0m";
            case 'cyan':       return "\033[36m{$text}\033[0m";
            case 'bold':       return "\033[1m{$text}\033[0m";
            case 'boldGreen':  return "\033[1;32m{$text}\033[0m";
            case 'boldCyan':   return "\033[1;36m{$text}\033[0m";
            case 'boldYellow': return "\033[1;33m{$text}\033[0m";
            default:           return $text;
        }
    }

    // ===== 旧API互換ショートカット =====
    public static function bold(string $s): string       { return self::colorize($s, 'bold'); }
    public static function green(string $s): string      { return self::colorize($s, 'green'); }
    public static function yellow(string $s): string     { return self::colorize($s, 'yellow'); }
    public static function red(string $s): string        { return self::colorize($s, 'red'); }
    public static function cyan(string $s): string       { return self::colorize($s, 'cyan'); }
    public static function boldGreen(string $s): string  { return self::colorize($s, 'boldGreen'); }
    public static function boldCyan(string $s): string   { return self::colorize($s, 'boldCyan'); }
    public static function boldYellow(string $s): string { return self::colorize($s, 'boldYellow'); }
}

