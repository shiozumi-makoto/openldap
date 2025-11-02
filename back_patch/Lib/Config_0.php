<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * 共通設定ローダ
 * - 優先度: CLI引数 > 環境変数 > デフォルト
 * - 既存の Tools\Lib\CliUtil / Tools\Ldap\Env があれば自動で利用
 * - 型: string/int/bool を標準サポート（必要に応じて拡張可）
 *
 * 使い方:
 *   $cfg = Config::load($argv, [
 *     'confirm' => ['cli'=>'confirm','type'=>'bool','default'=>false],
 *     'ldap'    => ['cli'=>'ldap',   'type'=>'bool','default'=>false],
 *     'bind_dn' => ['cli'=>'bind-dn','env'=>'BIND_DN','type'=>'string','default'=>'cn=Admin,dc=e-smile,dc=ne,dc=jp'],
 *     ...
 *   ]);
 */
final class Config
{
    /** CLI引数の取得（CliUtil::args があれば利用） */
    public static function parseArgs(array $argv): array
    {
        // 既存ユーティリティ優先
        if (class_exists(\Tools\Lib\CliUtil::class) && method_exists(\Tools\Lib\CliUtil::class, 'args')) {
            return \Tools\Lib\CliUtil::args($argv);
        }

        // フォールバック: --key=value / フラグ型(--flag) を簡易解析
        $out = [];
        foreach (array_slice($argv, 1) as $a) {
            if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
                $out[$m[1]] = $m[2];
            } elseif (preg_match('/^--(.+)$/', $a, $m)) {
                $out[$m[1]] = true; // 存在 = bool true
            }
        }
        return $out;
    }

    /** 環境変数の取得（Tools\Ldap\Env::get があれば優先） */
    public static function getenv(string $name, ?string $default=null): ?string
    {
        if (class_exists(\Tools\Ldap\Env::class) && method_exists(\Tools\Ldap\Env::class, 'get')) {
            $v = \Tools\Ldap\Env::get($name);
            if ($v !== null && $v !== '') return $v;
            return $default;
        }
        $v = \getenv($name);
        if ($v === false || $v === '') return $default;
        return $v;
    }

    /** 真偽の正規化 */
    public static function toBool($v, bool $default=false): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return $default;
        $s = strtolower(trim((string)$v));
        if ($s === '') return $default;
        return in_array($s, ['1','true','on','yes','y','enable','enabled'], true);
    }

    /** 数値の正規化 */
    public static function toInt($v, int $default=0): int
    {
        if ($v === null || $v === '') return $default;
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int)$v;
        return $default;
    }

    /**
     * 優先度マージ（CLI > ENV > default）
     *
     * $schema 例:
     *  'bind_dn' => ['cli'=>'bind-dn','env'=>'BIND_DN','type'=>'string','default'=>'...']
     *  'confirm' => ['cli'=>'confirm','type'=>'bool','default'=>false]
     *  'port'    => ['cli'=>'port','env'=>'PORT','type'=>'int','default'=>636]
     */
    public static function load(array $argv, array $schema): array
    {
        $args = self::parseArgs($argv);
        $cfg  = [];

        foreach ($schema as $key => $def) {
            $cliKey   = $def['cli']     ?? null;
            $envKey   = $def['env']     ?? null;
            $type     = $def['type']    ?? 'string';
            $default  = $def['default'] ?? null;

            // CLI
            $fromCli  = null;
            if ($cliKey !== null && array_key_exists($cliKey, $args)) {
                $fromCli = $args[$cliKey];
            }

            // ENV
            $fromEnv  = null;
            if ($envKey !== null) {
                $fromEnv = self::getenv($envKey);
            }

            // pick: CLI > ENV > default
            $raw = $fromCli ?? $fromEnv ?? $default;

            // 型変換
            if ($type === 'bool') {
                $val = self::toBool($raw, (bool)$default);
            } elseif ($type === 'int') {
                $val = self::toInt($raw, (int)$default);
            } else {
                $val = ($raw === null) ? null : (string)$raw;
            }

            $cfg[$key] = $val;
        }
        return $cfg;
    }
}

