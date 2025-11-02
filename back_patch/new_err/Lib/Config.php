<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * Config.php
 *  - $schema に従い「CLI > ENV > FILE > default」をマージして最終設定配列を返す
 *  - 型: bool/int/string, secret=true で Env::secret を利用
 *  - 設定ファイル対応: loadWithFile()（INI形式、= でも : でもOK / BOM・行末コメントも吸収）
 */
final class Config
{
    /**
     * @param string[] $argv
     * @param array<string,array<string,mixed>> $schema
     * @return array<string,mixed>
     */
    public static function load(array $argv, array $schema): array
    {
        // --- 否定フラグ (--no-xxx) の先読み ---
        $neg = self::scanNoFlags($argv);

        $longopts = [];
        foreach ($schema as $key => $def) {
            $cli  = $def['cli']  ?? $key;
            $type = $def['type'] ?? 'string';
            $longopts[] = $cli . ($type === 'bool' ? '' : ':');
        }
        $opt = getopt('', $longopts) ?: [];

        $cfg = [];
        foreach ($schema as $key => $def) {
            $cliName = $def['cli'] ?? $key;
            $type    = $def['type'] ?? 'string';
            $envKey  = $def['env'] ?? null;
            $secret  = (bool)($def['secret'] ?? false);

            // CLI（boolは --xxx でtrue / --no-xxx でfalse）
            if (isset($neg[$cliName]) && ($type === 'bool')) {
                $val = false;
            } elseif (array_key_exists($cliName, $opt)) {
                $val = ($type === 'bool') ? true : $opt[$cliName];
            } else {
                $val = null;
            }

            // ENV
            if ($val === null && $envKey) {
                if ($secret) {
                    $val = Env::secret($envKey, null);
                } else {
                    $val = match ($type) {
                        'bool'   => Env::bool($envKey, null),
                        'int'    => Env::int($envKey, null),
                        default  => Env::str($envKey, null),
                    };
                }
            }

            // default
            if ($val === null && array_key_exists('default', $def)) {
                $val = $def['default'];
            }

            // 型整形
            $cfg[$key] = self::cast($val, $type);
        }

        return $cfg;
    }

    /**
     * 設定ファイル（INI）も含めてマージ
     * - 優先順位: CLI > ENV > FILE > default
     *
     * @param string[] $argv
     * @param array<string,array<string,mixed>> $schema
     * @param ?string $defaultConfigPath
     * @return array<string,mixed>
     */
    public static function loadWithFile(array $argv, array $schema, ?string $defaultConfigPath = null): array
    {
        // 1) 設定ファイルパスの決定（--config / TOOLS_CONFIG / 既定）
        $cfgPath = null;
        foreach ($argv as $i => $a) {
            if (str_starts_with($a, '--config=')) { $cfgPath = substr($a, 9); break; }
            if ($a === '--config' && isset($argv[$i+1])) { $cfgPath = $argv[$i+1]; break; }
        }
        if (!$cfgPath) {
            $cfgPath = getenv('TOOLS_CONFIG') ?: $defaultConfigPath;
        }

        // 2) 設定ファイル(INI)を読む（= でも : でもOK / BOM・行末コメント吸収）
        $fileKv = [];
        if ($cfgPath && is_file($cfgPath)) {
            $raw = file_get_contents($cfgPath);
            if ($raw !== false) {
                // BOM除去
                $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
                // まずは標準パーサで（警告は握りつぶし）
                $tmp = tempnam(sys_get_temp_dir(), 'ini_') ?: null;
                if ($tmp) { file_put_contents($tmp, $raw); }
                $ini = $tmp ? @parse_ini_file($tmp, false, INI_SCANNER_RAW) : false;
                if ($tmp) { @unlink($tmp); }

                if (is_array($ini)) {
                    $fileKv = $ini;
                } else {
                    // フォールバック: ":" も "=" も受理するゆるいパーサ
                    $fileKv = self::parseLooseIni($raw);
                }
            }
        }

        // 3) CLI/ENV/FILE/default の順で解決
        $neg = self::scanNoFlags($argv);

        $longopts = [];
        foreach ($schema as $key => $def) {
            $cli  = $def['cli']  ?? $key;
            $type = $def['type'] ?? 'string';
            $longopts[] = $cli . ($type === 'bool' ? '' : ':');
        }
        $opt = getopt('', $longopts) ?: [];

        $cfg = [];
        foreach ($schema as $key => $def) {
            $cliName = $def['cli'] ?? $key;
            $type    = $def['type'] ?? 'string';
            $envKey  = $def['env'] ?? null;
            $secret  = (bool)($def['secret'] ?? false);

            // CLI（boolは --xxx でtrue / --no-xxx でfalse）
            if (isset($neg[$cliName]) && ($type === 'bool')) {
                $val = false;
            } elseif (array_key_exists($cliName, $opt)) {
                $val = ($type === 'bool') ? true : $opt[$cliName];
            } else {
                $val = null;
            }

            // ENV
            if ($val === null && $envKey) {
                if ($secret) {
                    $val = Env::secret($envKey, null);
                } else {
                    $val = match ($type) {
                        'bool'   => Env::bool($envKey, null),
                        'int'    => Env::int($envKey, null),
                        default  => Env::str($envKey, null),
                    };
                }
            }

            // FILE（envキー名/スキーマキー名/CLI名の順で採用）
            if ($val === null && $fileKv) {
                $candidates = array_unique(array_filter([$envKey, $key, $cliName]));
                foreach ($candidates as $k2) {
                    if ($k2 !== null && array_key_exists($k2, $fileKv)) {
                        $val = $fileKv[$k2];
                        break;
                    }
                }
            }

            // default
            if ($val === null && array_key_exists('default', $def)) {
                $val = $def['default'];
            }

            // 型整形
            $cfg[$key] = self::cast($val, $type);
        }

        return $cfg;
    }

    // ===== helpers =====

    /** bool/int/string の型整形 */
    private static function cast(mixed $val, string $type): mixed
    {
        if ($type === 'bool') {
            if (is_string($val)) {
                $t = strtolower($val);
                if (in_array($t, ['1','true','yes','on'], true)) return true;
                if (in_array($t, ['0','false','no','off',''], true)) return false;
            }
            return (bool)$val;
        }
        if ($type === 'int') {
            return ($val === null || $val === '') ? 0 : (int)$val;
        }
        if ($type === 'string') {
            return ($val === null) ? '' : (string)$val;
        }
        return $val;
    }

    /** --no-xxx を検出して [cliName=>true] の連想配列を返す */
    private static function scanNoFlags(array $argv): array
    {
        $neg = [];
        foreach ($argv as $a) {
            if (preg_match('/^--no-([A-Za-z0-9_.-]+)$/', $a, $m)) {
                $neg[$m[1]] = true;
            }
        }
        return $neg;
    }

    /** ":" も "=" も受理するゆるいINIパーサ（BOM/行末コメント対応） */
    private static function parseLooseIni(string $raw): array
    {
        $out = [];
        $lines = preg_split('/\R/u', $raw) ?: [];
        foreach ($lines as $ln) {
            if ($ln === '') continue;
            if (preg_match('/^\s*[#;]/', $ln)) continue;                    // コメント
            if (!preg_match('/^\s*([A-Za-z0-9_.-]+)\s*[:=]\s*(.*)$/u', $ln, $m)) continue;
            $k = trim($m[1]);
            $v = trim($m[2]);
            // 行末コメント除去
            $v = preg_replace('/\s+[#;].*$/', '', $v);
            // 文字列の両端の引用符を外す
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}

