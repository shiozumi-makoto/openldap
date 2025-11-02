#!/usr/bin/env php
<?php
#  php prune_maildir.php --maildir --maildir-days=120 --maildir-max-count=80000 --maildir-max-size=2000MB
/**
 * ---------------------------------------------------------------------------
 * prune_maildir.php
 * ---------------------------------------------------------------------------
 * 【概要】
 *   /home 以下のすべてのユーザーの Maildir（cur / new）を対象に、
 *   以下の条件で不要メールを自動削除するメンテナンススクリプトです。
 *
 *     ① 指定日数より古いメールを削除（--maildir-days）
 *     ② 総メッセージ件数の上限を超えた場合、古い順に削除（--maildir-max-count）
 *     ③ 総容量の上限を超えた場合、古い順に削除（--maildir-max-size）
 *
 *   ※ Dovecot の doveadm が利用可能な場合は、SAVEDBEFORE による安全な削除を優先します。
 *     無い場合はファイルの更新日時 (mtime) を基準として削除します。
 *
 * ---------------------------------------------------------------------------
 * 【特徴】
 *   - dry-run モード対応（--dry-run） … 削除せず削除予定を表示
 *   - verbose モード対応（--verbose） … 各ユーザーごとの詳細ログ表示
 *   - color 出力対応（情報:水色／警告:黄／削除:黄／完了:緑／エラー:赤）
 *   - 既存の prune_home_dirs.php 等から include 可能（MaildirPruner::use()）
 *
 * ---------------------------------------------------------------------------
 * 【利用例】
 *
 *   # 30日より古いメールを削除（削除計画のみ表示）
 *   php prune_maildir.php --maildir --maildir-days=30 --dry-run --verbose
 *
 *   # 実際に削除
 *   php prune_maildir.php --maildir --maildir-days=30
 *
 *   # 件数および容量の両制限を同時に適用
 *   php prune_maildir.php --maildir --maildir-max-count=80000 --maildir-max-size=15G
 *
 *   # 特定ユーザーのみ対象（ユーザー名が 12- で始まる）
 *   php prune_maildir.php --maildir --user-filter='^12-' --maildir-days=60
 *
 *   # Maildir 名やホームディレクトリを変更
 *   php prune_maildir.php --maildir --maildir-name=Maildir-lmtp --home=/srv/home --maildir-days=45
 *
 * ---------------------------------------------------------------------------
 * 【include利用例】
 *
 *   require_once __DIR__ . '/prune_maildir.php';
 *   MaildirPruner::use([
 *     'home' => '/home',
 *     'maildir-name' => 'Maildir',
 *     'maildir-days' => 30,
 *     'maildir-max-count' => 80000,
 *     'maildir-max-size' => '15G',
 *     'user-filter' => '^12-',
 *     'dry-run' => true,
 *     'verbose' => true,
 *   ]);
 *
 * ---------------------------------------------------------------------------
 * 【設置場所】
 *   /usr/local/etc/openldap/tools/prune_maildir.php
 *
 * ---------------------------------------------------------------------------
 * 【実行権限】
 *   chmod +x prune_maildir.php
 *
 * ---------------------------------------------------------------------------
 * 【作成者】
 *   E-Smile Holdings internal maintenance system
 * ---------------------------------------------------------------------------
 */



declare(strict_types=1);

final class MaildirPruner
{
    private static $hasCliColor = false;
    private static $Color;
    private static $LOG_PREFIX = '[maildir-prune]';

    private static function bootstrap(): void
    {
        // デフォルト色（ANSIエスケープ）
        self::$Color = (object)[
            'info' => "\033[36m[i]\033[0m",   // cyan
            'warn' => "\033[33m[!]\033[0m",   // yellow
            'err'  => "\033[31m[x]\033[0m",   // red
            'ok'   => "\033[32m[?]\033[0m",   // green
            'del'  => "\033[33m[-]\033[0m",   // yellow delete
        ];

        foreach ([
            __DIR__ . '/CliColor.php',
            __DIR__ . '/CliUtil.php',
            __DIR__ . '/Env.php',
            __DIR__ . '/Config.php',
        ] as $f) {
            if (is_file($f)) require_once $f;
        }

        if (class_exists('CliColor')) {
            self::$hasCliColor = true;
        }
    }

    public static function main(array $argv): int
    {
        self::bootstrap();
        [$opts, $verbose, $dryRun] = self::parseCli($argv);
        if (!isset($opts['maildir'])) return 0;
        return self::run($opts, $verbose, $dryRun);
    }

    public static function use(array $options): int
    {
        self::bootstrap();
        $verbose = (bool)($options['verbose'] ?? false);
        $dryRun  = (bool)($options['dry-run'] ?? false);
        $opts = [
            'maildir'            => true,
            'home'               => $options['home']            ?? '/home',
            'maildir-name'       => $options['maildir-name']    ?? 'Maildir',
            'user-filter'        => $options['user-filter']     ?? '',
            'maildir-days'       => $options['maildir-days']    ?? null,
            'maildir-max-count'  => $options['maildir-max-count'] ?? null,
            'maildir-max-size'   => $options['maildir-max-size'] ?? null,
        ];
        return self::run($opts, $verbose, $dryRun);
    }

    private static function parseCli(array $argv): array
    {
        $long = [
            'maildir', 'maildir-days::', 'maildir-max-count::', 'maildir-max-size::',
            'maildir-name::', 'home::', 'user-filter::', 'dry-run', 'verbose'
        ];
        $opts = getopt('', $long);
        return [$opts, isset($opts['verbose']), isset($opts['dry-run'])];
    }

    private static function run(array $opts, bool $verbose, bool $dryRun): int
    {
        $C = self::$Color;
        $print  = fn($msg) => print self::$LOG_PREFIX . " $msg\n";
        $vprint = fn($msg) => $verbose && print self::$LOG_PREFIX . " $msg\n";

        $home     = $opts['home'] ?? '/home';
        $maildir  = $opts['maildir-name'] ?? 'Maildir';
        $filter   = $opts['user-filter'] ?? '';
        $days     = isset($opts['maildir-days']) ? (int)$opts['maildir-days'] : null;
        $maxCount = isset($opts['maildir-max-count']) ? (int)$opts['maildir-max-count'] : null;
        $maxSize  = $opts['maildir-max-size'] ?? null;
        $maxBytes = $maxSize ? self::toBytes($maxSize) : null;

        $print("{$C->info} start; home={$home} maildir={$maildir} days=" .
               ($days ?? '-') . " count=" . ($maxCount ?? '-') . " size=" . ($maxBytes ?? '-') .
               " dry=" . ($dryRun?'1':'0'));

        $users = self::enumerateUsers($home, $maildir, $filter, $vprint);
        if (!$users) {
            $print("{$C->warn} no Maildir found under {$home}/*/{$maildir}");
            return 0;
        }

        $hasDoveadm = self::hasDoveadm();

        foreach ($users as $u => $md) {
            $print("== {$u} : {$md} ==");
            if ($days !== null) self::pruneByDays($u, $md, $days, $dryRun, $hasDoveadm, $print, $vprint);
            if ($maxCount !== null || $maxBytes !== null)
                self::pruneByCountSize($md, $maxCount, $maxBytes, $dryRun, $print, $vprint);
        }

        $print("{$C->ok} done.");
        return 0;
    }

    private static function enumerateUsers(string $home, string $maildir, string $filter, callable $vprint): array
    {
        $users = [];
        foreach (glob("{$home}/*") as $uDir) {
            $name = basename($uDir);
            if (!is_dir("$uDir/$maildir")) continue;
            if ($filter && !preg_match("~{$filter}~", $name)) continue;
            $users[$name] = "$uDir/$maildir";
        }
        $vprint("found " . count($users) . " users");
        return $users;
    }

    private static function listMailFiles(string $md): array
    {
        $arr = [];
        foreach (['cur','new'] as $s) {
            $dir = "$md/$s";
            if (!is_dir($dir)) continue;
            foreach (glob("$dir/*") as $f) if (is_file($f)) $arr[] = $f;
        }
        return $arr;
    }

    private static function pruneByDays(string $user, string $md, int $days, bool $dry, bool $dove, callable $p, callable $v): void
    {
        $C = self::$Color;
        if ($dove) {
            $cut = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
            $cmd = "doveadm expunge -u " . escapeshellarg($user) . " mailbox '*' SAVEDBEFORE $cut";
            if ($dry) { $p("{$C->warn} [DRY] $cmd"); return; }
            $v("exec: $cmd"); shell_exec("$cmd 2>&1");
            $p("{$C->del} doveadm expunge executed ($user >{$days}d)");
        } else {
            $cut = time() - ($days * 86400);
            $files = array_filter(self::listMailFiles($md), fn($f)=>@filemtime($f)<$cut);
            if (!$files) return;
            if ($dry) $p("{$C->info} [DRY] would delete " . count($files) . " old files (> {$days}d)");
            else {
                foreach ($files as $f) @unlink($f);
                $p("{$C->del} deleted " . count($files) . " files (> {$days}d)");
            }
        }
    }

    private static function pruneByCountSize(string $md, ?int $maxCount, ?int $maxBytes, bool $dry, callable $p, callable $v): void
    {
        $C = self::$Color;
        $files = self::listMailFiles($md);
        usort($files, fn($a,$b)=>(@filemtime($a)??0)<=>(@filemtime($b)??0));
        $count = count($files);
        $total = array_sum(array_map('filesize', $files));
        $v("current count={$count} bytes={$total}");

        $del = [];
        $kept = 0; $size = 0;
        foreach ($files as $f) {
            $sz = @filesize($f) ?: 0;
            $keep = true;
            if ($maxCount && $kept >= $maxCount) $keep = false;
            if ($keep && $maxBytes && $size + $sz > $maxBytes) $keep = false;
            if ($keep) { $kept++; $size += $sz; }
            else $del[] = $f;
        }

        if (!$del) { $v("thresholds OK"); return; }

        if ($dry) $p("{$C->info} [DRY] would delete " . count($del) . " to fit limits");
        else {
            foreach ($del as $f) @unlink($f);
            $p("{$C->del} deleted " . count($del) . " files (threshold trim)");
        }
    }

    private static function toBytes(string $s): ?int
    {
        $s = trim($s);
        if (preg_match('/^\d+$/', $s)) return (int)$s;
        if (!preg_match('/^(\d+)\s*([KkMmGg][Bb]?)?$/', $s, $m)) return null;
        $n = (int)$m[1]; $u = strtolower($m[2] ?? '');
        return match($u){
            'k','kb'=> $n*1024,
            'm','mb'=> $n*1024**2,
            'g','gb'=> $n*1024**3,
            default => $n
        };
    }

    private static function hasDoveadm(): bool
    {
        $out = @shell_exec('command -v doveadm 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }
}

if (PHP_SAPI==='cli' && realpath($argv[0])===__FILE__) {
    exit(MaildirPruner::main($argv));
}


