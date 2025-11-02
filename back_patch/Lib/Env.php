<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * Env.php
 *  - 環境変数の型取得・秘密値解決(@file:/@env:)・dotenvローダの簡易実装
 */
final class Env
{
    public static function str(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : (string)$v;
    }

    public static function int(string $key, ?int $default = null): ?int {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;
        return (int)$v;
    }

    public static function bool(string $key, ?bool $default = null): ?bool {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;
        $t = strtolower(trim($v));
        return in_array($t, ['1','true','on','yes','y'], true) ? true
             : (in_array($t, ['0','false','off','no','n'], true) ? false : $default);
    }

    /**
     * 秘密値の解決。値 / @file:/path / @env:OTHER に対応
     */
    public static function secret(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;

        if (str_starts_with($v, '@file:')) {
            $path = substr($v, 6);
            $data = @file_get_contents($path);
            return $data === false ? $default : rtrim($data, "\r\n");
        }
        if (str_starts_with($v, '@env:')) {
            $other = substr($v, 5);
            $o = getenv($other);
            return $o === false ? $default : (string)$o;
        }
        return (string)$v;
    }

    /**
     * .env ローダ（任意利用）
     * - 既に設定済みの環境変数は上書きしない
     */
    public static function loadDotenv(string $path): void {
        if (!is_file($path)) return;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === '') continue;
            if (getenv($k) === false) {
                putenv($k . '=' . $v);
            }
        }
    }
}

