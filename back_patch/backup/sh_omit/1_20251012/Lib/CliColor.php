<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * シンプルな CLI カラー出力ユーティリティ
 * - TTY のときだけ色を付ける（パイプ/リダイレクトでは素の文字）
 * - 必要最低限のAPI: bold, red, yellow, green, cyan, boldRed, boldYellow, boldGreen, boldCyan
 */
final class CliColor
{
    /** ANSIを使ってよいか（STDOUT が TTY か） */
    private static function on(): bool
    {
        if (PHP_SAPI !== 'cli') return false;
        if (function_exists('posix_isatty')) {
            // STDOUTが定義されない環境はほぼ無いが、保険で
            $fd = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
            return @posix_isatty($fd);
        }
        // posix_isatty が無い環境では一律オン（必要なら環境変数で切替）
        return getenv('NO_COLOR') === false;
    }

    /** 汎用ラッパ */
    private static function wrap(string $s, string $code): string
    {
        if (!self::on() || $s === '') return $s;
        return "\033[" . $code . "m" . $s . "\033[0m";
    }

    // ===== 基本スタイル =====
    public static function bold(string $s): string      { return self::wrap($s, '1'); }
    public static function red(string $s): string       { return self::wrap($s, '31'); }
    public static function yellow(string $s): string    { return self::wrap($s, '33'); }
    public static function green(string $s): string     { return self::wrap($s, '32'); }
    public static function cyan(string $s): string      { return self::wrap($s, '36'); }

    // ===== 太字＋色 =====
    public static function boldRed(string $s): string    { return self::wrap($s, '1;31'); }
    public static function boldYellow(string $s): string { return self::wrap($s, '1;33'); }
    public static function boldGreen(string $s): string  { return self::wrap($s, '1;32'); }
    public static function boldCyan(string $s): string   { return self::wrap($s, '1;36'); }

    // ===== おまけ =====
    /** 色コードを除去（ログ保存時など） */
    public static function strip(string $s): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? $s;
    }
}


/*
namespace Tools\Ldap;

final class CliColor {
    public static function enabled(): bool {
        if (PHP_SAPI !== 'cli') return false;
        if (getenv('NO_COLOR')) return false;
        if (getenv('FORCE_COLOR')) return true;
        return true; // デフォルトON（必要ならNO_COLORで無効化）
    }

    public static function ansi(string $text, string $style): string {
        return self::enabled() ? "\033[{$style}m{$text}\033[0m" : $text;
    }

    public static function boldRed(string $t): string { return self::ansi($t, '1;31'); }
    public static function red(string $t): string     { return self::ansi($t, '31'); }
    public static function yellow(string $t): string  { return self::ansi($t, '33'); }
    public static function dim(string $t): string     { return self::ansi($t, '2'); }
}
*/
