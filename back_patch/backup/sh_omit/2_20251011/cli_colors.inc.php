<?php
declare(strict_types=1);

/**
 * cli_colors.inc.php
 * -------------------------------------------
 * CLI出力を彩るANSIカラー＆書式ユーティリティ集
 *
 * - PHPスクリプト実行時に色付きログを出したいとき用
 * - 標準出力がTTYでない場合（リダイレクトなど）は自動で色を無効化
 * - 環境変数で制御:
 *     NO_COLOR=1      → 強制無効
 *     FORCE_COLOR=1   → 強制有効
 *
 * 使用例:
 *   include '/usr/local/etc/openldap/tools/cli_colors.inc.php';
 *   echo bold_red("[ERROR] Something failed\n");
 *   echo yellow("Warning: dry-run mode\n");
 */

/* ==== 状態判定 ==== */

if (!function_exists('ansi_enabled')) {
    function ansi_enabled(): bool {
        // CLI環境でなければ無効
        if (PHP_SAPI !== 'cli') return false;
        // 明示制御
        if (getenv('NO_COLOR')) return false;
        if (getenv('FORCE_COLOR')) return true;

        // TTY判定
        if (function_exists('posix_isatty') && @posix_isatty(STDOUT)) return true;
        if (stripos(PHP_OS, 'WIN') === 0 && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
            return true;
        }

        // 不明な場合はON（TTYでなくても強制カラーにしたい場合）
        return true;
    }
}

/* ==== カラー適用 ==== */

if (!function_exists('ansi')) {
    /**
     * ANSIカラー適用
     * @param string $text 対象文字列
     * @param string $style ANSIコード（例: '1;31' → 太字赤）
     */
    function ansi(string $text, string $style): string {
        return ansi_enabled() ? "\033[{$style}m{$text}\033[0m" : $text;
    }
}

/* ==== 定番カラーショートカット ==== */

if (!function_exists('bold_red')) {
    function bold_red(string $text): string { return ansi($text, '1;31'); }
}
if (!function_exists('red')) {
    function red(string $text): string { return ansi($text, '31'); }
}
if (!function_exists('green')) {
    function green(string $text): string { return ansi($text, '32'); }
}
if (!function_exists('yellow')) {
    function yellow(string $text): string { return ansi($text, '33'); }
}
if (!function_exists('blue')) {
    function blue(string $text): string { return ansi($text, '34'); }
}
if (!function_exists('magenta')) {
    function magenta(string $text): string { return ansi($text, '35'); }
}
if (!function_exists('cyan')) {
    function cyan(string $text): string { return ansi($text, '36'); }
}
if (!function_exists('dim')) {
    function dim(string $text): string { return ansi($text, '2'); }
}
if (!function_exists('bold')) {
    function bold(string $text): string { return ansi($text, '1'); }
}
if (!function_exists('underline')) {
    function underline(string $text): string { return ansi($text, '4'); }
}

/* ==== 補助 ==== */

if (!function_exists('color_test')) {
    /** テスト出力 */
    function color_test(): void {
        echo bold_red("BOLD RED"), "\n";
        echo red("RED"), "\n";
        echo green("GREEN"), "\n";
        echo yellow("YELLOW"), "\n";
        echo blue("BLUE"), "\n";
        echo magenta("MAGENTA"), "\n";
        echo cyan("CYAN"), "\n";
        echo dim("DIM"), "\n";
        echo bold("BOLD"), "\n";
        echo underline("UNDERLINE"), "\n";
    }
}


