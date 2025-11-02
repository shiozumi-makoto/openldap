<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * Config.php
 *  - $schema に従い「CLI > ENV > default」をマージして最終設定配列を返す
 *  - 型: bool/int/string, secret=true で Env::secret を利用
 *  - 設定ファイル対応: loadWithFile()（INI形式、優先順位は CLI > ENV > FILE > default）
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

            // CLI（boolはキー存在でtrue）
            if (array_key_exists($cliName, $opt)) {
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
            if ($type === 'bool') {
                $val = (bool)$val;
            } elseif ($type === 'int' && $val !== null && $val !== '') {
                $val = (int)$val;
            } elseif ($type === 'string' && $val !== null) {
                $val = (string)$val;
            }

            $cfg[$key] = $val;
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
            if (str_starts_with($a, '--config=')) {
                $cfgPath = substr($a, 9);
                break;
            }
            if ($a === '--config' && isset($argv[$i+1])) {
                $cfgPath = $argv[$i+1];
                break;
            }
        }
        if (!$cfgPath) {
            $cfgPath = getenv('TOOLS_CONFIG') ?: $defaultConfigPath;
        }

        // 2) 設定ファイル(INI)を読む
        $fileKv = [];
        if ($cfgPath && is_file($cfgPath)) {
            $ini = parse_ini_file($cfgPath, false, INI_SCANNER_TYPED);
            if (is_array($ini)) {
                foreach ($ini as $k => $v) {
                    $fileKv[$k] = $v;
                }
            }
        }

        // 3) CLI/ENV/FILE/default の順で解決
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

            // CLI（boolはキー存在でtrue）
            if (array_key_exists($cliName, $opt)) {
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
            if ($val === null) {
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
            if ($type === 'bool') {
                $val = (bool)$val;
            } elseif ($type === 'int' && $val !== null && $val !== '') {
                $val = (int)$val;
            } elseif ($type === 'string' && $val !== null) {
                $val = (string)$val;
            }

            $cfg[$key] = $val;
        }

        return $cfg;
    }
}


