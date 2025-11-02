<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * Env
 *  - 環境変数・既定値・*_FILE 読み込みサポート
 *  - 文字列/整数/真偽/JSON/秘匿値の取得API
 */
final class Env
{
    /** そのまま（null 許容） */
    public static function get(string $key, $default = null)
    {
        $val = getenv($key);
        if ($val !== false && $val !== null) return $val;

        // *_FILE があればファイルから読み込む
        $fileKey = $key . '_FILE';
        $path = getenv($fileKey);
        if ($path !== false && $path !== null && $path !== '') {
            $s = self::readFileTrim((string)$path);
            if ($s !== null) return $s;
        }
        return $default;
    }

    /** 文字列として取得（null → 既定値） */
    public static function str(string $key, ?string $default = null): ?string
    {
        $v = self::get($key, $default);
        return $v === null ? null : (string)$v;
    }

    /**
     * 整数として取得（null 既定値も許容）
     * - $default に null を渡すと「見つからなければ null を返す」
     */
    public static function int(string $key, ?int $default = null): ?int
    {
        $v = self::get($key, null);
        if ($v === null || $v === '') return $default;
        if (is_numeric($v)) return (int)$v;
        return $default;
    }

    /**
     * 整数（厳格版）
     * - 常に int を返したい場合はこちらを使用（未設定や不正値なら $default）
     */
    public static function intStrict(string $key, int $default = 0): int
    {
        $v = self::get($key, null);
        if ($v === null || $v === '' || !is_numeric($v)) return $default;
        return (int)$v;
    }

    /** 真偽として取得（"1/true/on/yes"→true, "0/false/off/no"→false） */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null) return $default;
        $s = strtolower(trim((string)$v));
        if ($s === '1' || $s === 'true' || $s === 'on' || $s === 'yes') return true;
        if ($s === '0' || $s === 'false' || $s === 'off' || $s === 'no') return false;
        return $default;
    }

    /** JSON として取得（壊れていれば既定値） */
    public static function json(string $key, $default = null)
    {
        $v = self::get($key, null);
        if ($v === null || $v === '') return $default;
        $dec = json_decode((string)$v, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $dec : $default;
    }

    /**
     * 秘匿値（パスワードなど）
     *  - まず KEY、無ければ KEY_FILE を見てファイル内容（trim）を返す
     */
    public static function secret(string $key, ?string $default = null): ?string
    {
        $v = self::get($key, null);
        if ($v !== null && $v !== '') return (string)$v;
        return $default;
    }

    /** ホームチルダ展開（~ or ~/path を絶対パスへ） */
    public static function expandHome(string $path): string
    {
        $path = (string)$path;
        if ($path === '' || $path[0] !== '~') return $path;

        $home = getenv('HOME') ?: null;
        if ($home && ($path === '~' || str_starts_with($path, '~/'))) {
            return $home . substr($path, 1);
        }
        return $path;
    }

    /** 読めるファイルなら内容を trim して返す（失敗時 null） */
    public static function readFileTrim(string $path): ?string
    {
        $p = self::expandHome($path);
        if (!is_file($p) || !is_readable($p)) return null;
        $s = @file_get_contents($p);
        if ($s === false) return null;
        return trim($s);
    }
}
