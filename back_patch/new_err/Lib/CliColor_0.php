<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * CliColor
 *  - ANSIカラーを手軽に使うためのユーティリティ
 *  - 端末がTTYでない / NO_COLOR 環境変数がある場合は自動で無効化
 *  - 主要色 + 明色（bright）に対応
 *
 * 使い方:
 *   CliColor::printf("Hello %s\n", "world", 'green');
 *   echo CliColor::colorize("[WARN] message", 'yellow');
 *   $plain = CliColor::strip($colored);
 */
final class CliColor
{
    /** @var bool 出力に色を付けるかどうか（自動判定＋上書き可） */
    private static ?bool $enabled = null;

    /** @var array<string,string> カラー名→ANSIコード */
    private static array $map = [
        'black'   => "0;30",
        'red'     => "0;31",
        'green'   => "0;32",
        'yellow'  => "0;33",
        'blue'    => "0;34",
        'magenta' => "0;35",
        'cyan'    => "0;36",
        'white'   => "0;37",

        'bright_black'   => "1;30",
        'bright_red'     => "1;31",
        'bright_green'   => "1;32",
        'bright_yellow'  => "1;33",
        'bright_blue'    => "1;34",
        'bright_magenta' => "1;35",
        'bright_cyan'    => "1;36",
        'bright_white'   => "1;37",
    ];

    /** 色出力の有効/無効を明示的に設定 */
    public static function setEnabled(bool $on): void
    {
        self::$enabled = $on;
    }

    /** 現在の色出力が有効かどうか（初回は自動判定） */
    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) return self::$enabled;

        // NO_COLOR が設定されていたら無効
        if (getenv('NO_COLOR') !== false) {
            self::$enabled = false;
            return false;
        }

        // STDOUT が TTY のときに有効（Windows も大半の環境でOK）
        $isTty = function_exists('stream_isatty')
            ? @stream_isatty(STDOUT)
            : function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;

        self::$enabled = (bool)$isTty;
        return self::$enabled;
    }

    /**
     * 文字列に色を付ける
     * @param string $text
     * @param string|null $color 例: 'red', 'bright_cyan' など。nullなら無色。
     */
    public static function colorize(string $text, ?string $color): string
    {
        if (!$color || !isset(self::$map[$color]) || !self::isEnabled()) {
            return $text;
        }
        $code = self::$map[$color];
        return "\033[" . $code . "m" . $text . "\033[0m";
    }

    /**
     * printf しつつ最後に色付け（format の適用後に color を掛けます）
     * @param string      $format
     * @param mixed       ...$args  最後の引数に色名を渡せます（省略可）
     *
     * 例:
     *   CliColor::printf("Done: %d rows\n", $n, 'green');
     *   CliColor::printf("[%s] %s\n", 'WARN', 'message', 'yellow');
     */
    public static function printf(string $format, ...$args): void
    {
        $color = null;
        if (!empty($args)) {
            $last = end($args);
            if (is_string($last) && isset(self::$map[$last])) {
                $color = $last;
                array_pop($args);
            }
        }
        $s = vsprintf($format, $args);
        echo self::colorize($s, $color);
    }

    /** ANSI コードを除去してプレーンテキストにする */
    public static function strip(string $s): string
    {
        return (string)preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $s);
    }
}
