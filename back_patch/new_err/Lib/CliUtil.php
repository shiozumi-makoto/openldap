<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * CliUtil
 *  - シンプルな CLI フラグ/オプション解析
 *  - 旧来スクリプト互換の getOptFlags()
 *  - ヘルプ整形 buildHelp()
 *
 * 使い方:
 *   [$opts, $args] = CliUtil::getOptFlags(['verbose','confirm','name:','count:']);
 *   // --verbose → isset($opts['verbose'])
 *   // --name=alice → $opts['name'] === 'alice'
 *   // 位置引数は $args に入る
 */
final class CliUtil
{
    /**
     * getOptFlags
     *  - $defs には "--key" or "--key=value" 相当の定義を書く
     *    例: ['verbose', 'confirm', 'name:', 'count:']
     *        ':' が付くものは値付き（--name=alice）
     *
     * @param array $defs
     * @param array|null $argv  省略時は $_SERVER['argv']
     * @return array [array $opts, array $args]
     */
    public static function getOptFlags(array $defs, ?array $argv = null): array
    {
        if ($argv === null) {
            $argv = $_SERVER['argv'] ?? [];
        }
        // 先頭はスクリプト名なので落とす
        if (!empty($argv)) array_shift($argv);

        // 受理テーブル作成
        $wantValue = [];
        foreach ($defs as $d) {
            if (substr($d, -1) === ':') {
                $wantValue[substr($d, 0, -1)] = true;
            } else {
                $wantValue[$d] = false;
            }
        }

        $opts = [];
        $args = [];

        while (($a = array_shift($argv)) !== null) {
            if (strpos($a, '--') !== 0) {
                $args[] = $a; // 位置引数
                continue;
            }

            // "--key" or "--key=value"
            $eq = strpos($a, '=');
            if ($eq === false) {
                $key = substr($a, 2);
                if ($key === '') continue;

                if (array_key_exists($key, $wantValue)) {
                    if ($wantValue[$key]) {
                        // 値が必要 → 次の引数を取る（--key value 形式にも一応対応）
                        $peek = $argv[0] ?? null;
                        if ($peek !== null && strpos((string)$peek, '--') !== 0) {
                            $opts[$key] = array_shift($argv);
                        } else {
                            // 値が無いときは空文字で受理
                            $opts[$key] = '';
                        }
                    } else {
                        // フラグ
                        $opts[$key] = true;
                    }
                } else {
                    // 未定義キーは無視
                }
            } else {
                $key = substr($a, 2, $eq - 2);
                $val = substr($a, $eq + 1);
                if ($key === '') continue;

                if (array_key_exists($key, $wantValue)) {
                    if ($wantValue[$key]) {
                        $opts[$key] = $val;
                    } else {
                        // フラグに値を与えた場合は true 扱い（値は無視）
                        $opts[$key] = true;
                    }
                } else {
                    // 未定義キーは無視
                }
            }
        }

        return [$opts, $args];
    }

    /**
     * buildHelp
     *  - Config::loadWithFile で使う $schema からヘルプを整形
     *
     * @param array  $schema  例: ['verbose'=>['cli'=>'verbose','type'=>'bool','default'=>false,'desc'=>'詳細']]
     * @param string $prog    プログラム名
     * @param array  $examples ['説明'=>'コマンド', ...]
     * @return string
     */
    public static function buildHelp(array $schema, string $prog, array $examples = []): string
    {
        $lines = [];
        $lines[] = "Usage: php {$prog} [options]";
        $lines[] = "";
        $lines[] = "Options:";

        // 最大幅を粗取りして整形
        $keys = [];
        foreach ($schema as $k => $def) {
            $cli = $def['cli'] ?? $k;
            $type = $def['type'] ?? 'bool';
            $hasVal = ($type !== 'bool');
            $opt = '--' . $cli . ($hasVal ? '=VALUE' : '');
            $keys[] = $opt;
        }
        $pad = 0;
        foreach ($keys as $o) $pad = max($pad, strlen($o));
        $pad += 2;

        $i = 0;
        foreach ($schema as $k => $def) {
            $cli  = $def['cli'] ?? $k;
            $type = $def['type'] ?? 'bool';
            $desc = $def['desc'] ?? '';
            $defv = array_key_exists('default', $def) ? $def['default'] : null;

            $opt = '--' . $cli . ($type !== 'bool' ? '=VALUE' : '');
            $rhs = $desc;

            if ($defv !== null) {
                $rhs .= " (default: " . self::stringifyDefault($defv) . ")";
            }
            $lines[] = "  " . str_pad($opt, $pad) . $rhs;
            $i++;
        }

        if (!empty($examples)) {
            $lines[] = "";
            $lines[] = "Examples:";
            foreach ($examples as $caption => $cmd) {
                $lines[] = "  - {$caption}";
                $lines[] = "      {$cmd}";
            }
        }
        $lines[] = "";

        return implode("\n", $lines);
    }

    /** 既定値の文字列化（ヘルプ用） */
    private static function stringifyDefault($v): string
    {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if ($v === null) return 'null';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return (string)$v;
    }

    /** 現在のコマンドラインを文字列で返す（表示用） */
    public static function argvString(?array $argv = null): string
    {
        if ($argv === null) $argv = $_SERVER['argv'] ?? [];
        // 先頭のスクリプト名は除外
        $args = $argv;
        array_shift($args);
        return implode(' ', array_map('escapeshellarg', $args));
    }
}


