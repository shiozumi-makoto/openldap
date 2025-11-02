<?php
declare(strict_types=1);

namespace Tools\Lib;

/**
 * CliUtil.php
 *  - スキーマ駆動の --help 自動生成
 */
final class CliUtil
{
    /**
     * スキーマから --help テキストを生成
     *
     * @param array<string,array<string,mixed>> $schema
     * @param string $prog   実行ファイル名
     * @param array<string,string> $samples 見出し => コマンド例 の配列
     */
    public static function buildHelp(array $schema, string $prog, array $samples = []): string
    {
        $lines   = [];
        $lines[] = "使い方: php {$prog} [オプション]";
        $lines[] = "";
        $lines[] = "オプション:";

        foreach ($schema as $key => $def) {
            $opt  = $def['cli'] ?? $key;
            $type = $def['type'] ?? 'string';
            $desc = $def['desc'] ?? '';
            $env  = $def['env'] ?? null;
            $hint = ($type === 'bool') ? '' : '=VALUE';
            $envs = $env ? " (env: {$env})" : '';
            $lines[] = sprintf("  --%-16s %s%s", $opt . $hint, $desc, $envs);
        }

        if ($samples) {
            $lines[] = "";
            $lines[] = "サンプル:";
            foreach ($samples as $cap => $cmd) {
                $lines[] = "  - {$cap}";
                $lines[] = "    {$cmd}";
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}

