#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prune_home_dirs.php
 * - /home/<uid> -> <home_root>/<NN-NNN-uid> の構成を前提に、
 *   LDAP に存在しない uid のリンク/実体を整理
 */

require __DIR__ . '/autoload.php';

use Tools\Lib\Config;
use Tools\Lib\CliUtil;
use Tools\Lib\LdapConnector;
use Tools\Lib\Env;

$schema = [
    'help'        => ['cli'=>'help','type'=>'bool','default'=>false,'desc'=>'このヘルプを表示'],
    'confirm'     => ['cli'=>'confirm','type'=>'bool','default'=>false,'desc'=>'実行（未指定はDRY-RUN）'],
    'verbose'     => ['cli'=>'verbose','type'=>'bool','default'=>false,'desc'=>'詳細ログ'],

    'uri'         => ['cli'=>'uri','type'=>'string','env'=>'LDAP_URI','default'=>null,'desc'=>'ldap[s]/ldapi URI'],
    'ldapi'       => ['cli'=>'ldapi','type'=>'bool','default'=>false,'desc'=>'ldapi を使う'],
    'ldaps'       => ['cli'=>'ldaps','type'=>'bool','default'=>false,'desc'=>'ldaps を使う'],
    'bind_dn'     => ['cli'=>'bind-dn','type'=>'string','env'=>'LDAP_BIND_DN','default'=>null,'desc'=>'Bind DN'],
    'bind_pass'   => ['cli'=>'bind-pass','type'=>'string','env'=>'LDAP_BIND_PASS','secret'=>true,'default'=>null,'desc'=>'Bind パス'],
    'base_dn'     => ['cli'=>'base-dn','type'=>'string','env'=>'LDAP_BASE_DN','default'=>null,'desc'=>'Base DN'],

    'home_root'   => ['cli'=>'home-root','type'=>'string','default'=>'/ovs012_home','desc'=>'ホーム実体ルート'],
    'days'        => ['cli'=>'days','type'=>'int','default'=>30,'desc'=>'最終更新が N 日より古いものだけ対象'],
    'archive_dir' => ['cli'=>'archive-dir','type'=>'string','default'=>null,'desc'=>'実体を削除せずここへ移動（既定: <home_root>/_archive）'],
    'hard_delete' => ['cli'=>'hard-delete','type'=>'bool','default'=>false,'desc'=>'実体を完全削除（危険）'],
];

$cfg = Config::loadWithFile($argv, $schema, __DIR__ . '/inc/tools.conf');
if (!empty($cfg['help'])) {
    $prog = basename($_SERVER['argv'][0] ?? 'prune_home_dirs.php');
    echo CliUtil::buildHelp($schema, $prog, [
        'DRY-RUN' => "php {$prog} --ldapi --verbose",
        '実行'    => "php {$prog} --ldapi --confirm",
    ]);
    exit(0);
}
$APPLY = !empty($cfg['confirm']);
$VERB  = !empty($cfg['verbose']);
$DBG   = fn(string $m) => $VERB && print("[DBG] {$m}\n");

echo '  php ' . basename(__FILE__) . ' ' . implode(' ', array_slice($_SERVER['argv'], 1)) . "\n\n";

// LDAP
[$ds, $baseDn, /*$groupsDn*/, $uri] = LdapConnector::connect($cfg, $DBG);
$baseDn = $baseDn ?: Env::str('LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');

// 設定
$homeRoot = rtrim((string)$cfg['home_root'], '/');
$archive  = $cfg['archive_dir'] ?: ($homeRoot.'/_archive');
$olderSec = ((int)$cfg['days']) * 86400;
$now      = time();

echo "=== START prune-home ===\n";
printf("HOME_ROOT : %s\n", $homeRoot);
printf("ARCHIVE   : %s\n", $cfg['hard_delete'] ? '(disabled by --hard-delete)' : $archive);
printf("OLDER THAN: %d days\n", (int)$cfg['days']);
printf("CONFIRM   : %s\n", $APPLY ? "YES (execute)" : "NO  (dry-run)");
echo "----------------------------------------------\n\n";

// /home のシンボリックリンク列挙
$linkDir = '/home';
$entries = @scandir($linkDir) ?: [];
$targets = [];
foreach ($entries as $e) {
    if ($e === '.' || $e === '..') continue;
    $path = "{$linkDir}/{$e}";
    if (is_link($path)) {
        $to = readlink($path);
        $targets[] = ['uid'=>$e,'link'=>$path,'to'=>$to];
    }
}

// LDAP に存在する uid セット
$exists = [];
if ($ds) {
    $res = @ldap_search($ds, $baseDn, '(uid=*)', ['uid'], 0, 0, 30);
    if ($res) {
        $entry = @ldap_first_entry($ds,$res);
        while ($entry) {
            $a = @ldap_get_attributes($ds,$entry);
            if (!empty($a['uid'][0])) $exists[(string)$a['uid'][0]] = true;
            $entry = @ldap_next_entry($ds,$entry);
        }
    }
}

// 判定と処理
$stats = ['unlink'=>0,'archive'=>0,'delete'=>0,'skip_recent'=>0];
if (!is_dir($archive) && !$cfg['hard_delete']) {
    if ($APPLY) @mkdir($archive, 0770, true);
}

foreach ($targets as $t) {
    $uid = $t['uid'];
    $link= $t['link'];
    $to  = $t['to'];
    $real= $to;
    if (!str_starts_with($real, $homeRoot.'/')) continue; // 他所は触らない

    $mtime = @filemtime($real) ?: @lstat($link)['mtime'] ?? $now;
    $age   = $now - (int)$mtime;
    $oldOk = ($age >= $olderSec);

    // LDAP に uid が存在すればスキップ
    if (isset($exists[$uid])) continue;

    // 表示
    printf("Orphan [%-20s] link=%s\n", $uid, $link);

    if (!$oldOk) { echo "  [SKIP] 最近更新されています（閾値未満）\n"; $stats['skip_recent']++; continue; }

    // 1) /home のリンクを外す
    if ($APPLY) {
        @unlink($link) ? $stats['unlink']++ : fwrite(STDERR,"[ERROR] unlink 失敗: {$link}\n");
    } else echo "  [DRY] unlink {$link}\n";

    // 2) 実体（archive or delete）
    if (is_dir($real)) {
        if ($cfg['hard_delete']) {
            if ($APPLY) {
                exec('rm -rf '.escapeshellarg($real), $o, $rc);
                if ($rc===0) $stats['delete']++; else fwrite(STDERR,"[ERROR] rm -rf 失敗: {$real}\n");
            } else echo "  [DRY] rm -rf {$real}\n";
        } else {
            $dest = $archive . '/' . basename($real) . '.' . date('Ymd_His');
            if ($APPLY) {
                @rename($real, $dest) ? $stats['archive']++ : fwrite(STDERR,"[ERROR] archive 失敗: {$real} -> {$dest}\n");
            } else echo "  [DRY] mv {$real} {$dest}\n";
        }
    }
}

echo "\n★ 完了: unlink={$stats['unlink']} archive={$stats['archive']} delete={$stats['delete']} skip_recent={$stats['skip_recent']} (" . ($APPLY?"EXECUTE":"DRY-RUN") . ")\n";
echo "=== DONE ===\n";
exit(0);


