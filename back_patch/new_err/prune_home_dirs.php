#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * prune_home_dirs.php
 * ---------------------------------------------------------------
 * LDAP に存在しない uid のホームディレクトリを整理
 *  - /home 配下のシンボリックリンク or 実体ディレクトリに対応
 *  - --days=N より古いものをアーカイブまたは削除
 *  - アーカイブは --archive-dir=/backup/_archive_home などに保存
 * ---------------------------------------------------------------
 */

use Tools\Ldap\Support\LdapUtil;
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Lib\Env;

require_once __DIR__ . '/autoload.php';

// ===============================================================
// CLIオプション解析（既存スタイル互換）
// ===============================================================
[$opts, $args] = CliUtil::getOptFlags([
    'ldapi', 'ldaps', 'uri:', 'days:', 'home-root:', 'archive-dir:',
    'hard-delete', 'confirm', 'verbose'
]);

$ldapi       = isset($opts['ldapi']);
$ldaps       = isset($opts['ldaps']);
$uri         = $opts['uri'] ?? null;
$days        = isset($opts['days']) ? (int)$opts['days'] : 30;
$homeRoot    = isset($opts['home-root']) ? rtrim((string)$opts['home-root'], '/') : '/home';
$archiveDir  = isset($opts['archive-dir']) ? rtrim((string)$opts['archive-dir'], '/') : '/home/_archive_deleted';
$HARD_DELETE = isset($opts['hard-delete']);
$APPLY       = isset($opts['confirm']);
$VERBOSE     = isset($opts['verbose']);

// ===============================================================
// LDAP接続設定
// ===============================================================
$ldapUri = $uri ?: ($ldapi
    ? 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
    : ($ldaps ? 'ldaps://ovs-012.e-smile.local:636' : 'ldap://localhost'));

echo "=== LDAP connection summary ===\n";
printf(" URI       : %s\n", $ldapUri);
printf(" CONFIRM   : %s\n", $APPLY ? 'YES (execute)' : 'NO (dry-run)');
printf(" HARD_DEL  : %s\n", $HARD_DELETE ? 'YES' : 'NO');
printf(" THRESHOLD : %d days\n", $days);
printf(" HOME_ROOT : %s\n", $homeRoot);
printf(" ARCHIVE   : %s\n", $archiveDir);
echo "=================================\n\n";

// ===============================================================
// LDAP 有効uid一覧取得
// ===============================================================
$ldap = LdapUtil::connect($ldapUri);
$baseDn = Env::get('LDAP_BASE_DN', 'dc=e-smile,dc=ne,dc=jp');
$peopleOu = "ou=Users,{$baseDn}";
$entries = LdapUtil::readEntries($ldap, $peopleOu, '(uid=*)', ['uid']);
$ldapUids = array_map(fn($e) => strtolower($e['uid'][0] ?? ''), $entries);
$ldapUidSet = array_flip($ldapUids);
printf("[INFO] LDAP 有効 uid 数: %d 件\n", count($ldapUids));

// ===============================================================
// /home のリンク or 実体を列挙
// ===============================================================
$linkDir = '/home';
$entries = @scandir($linkDir) ?: [];
$targets = [];

foreach ($entries as $e) {
    if ($e === '.' || $e === '..') continue;
    $path = "{$linkDir}/{$e}";

    if (is_link($path)) {
        $to = readlink($path);
        $targets[] = ['uid'=>$e, 'link'=>$path, 'real'=>$to, 'type'=>'link'];
    } elseif (is_dir($path) && preg_match('/^\d{2}-\d{3}-(.+)$/', $e, $m)) {
        // 実体ディレクトリ命名: NN-NNN-uid
        $uid = strtolower($m[1]);
        $targets[] = ['uid'=>$uid, 'link'=>$path, 'real'=>$path, 'type'=>'dir'];
    }
}

printf("[INFO] 検出ホーム数: %d 件 (/home)\n", count($targets));

// ===============================================================
// 古い・孤児ホーム検出
// ===============================================================
$now = time();
$limit = $now - ($days * 86400);
$orphans = [];

foreach ($targets as $t) {
    $uid = $t['uid'];
    $real = $t['real'];
    if (isset($ldapUidSet[$uid])) continue; // LDAPに存在 → skip

    $mtime = @filemtime($real);
    if ($mtime === false) continue;
    if ($mtime > $limit) continue; // 最近更新あり → skip

    $orphans[] = $t;
}

printf("[INFO] 孤児ホーム候補: %d 件\n", count($orphans));
if (!$orphans) {
    echo "[DONE] 対象なし\n";
    exit(0);
}

// ===============================================================
// アーカイブ or 削除処理
// ===============================================================
$stats = ['archive'=>0, 'delete'=>0, 'unlink'=>0];
if (!is_dir($archiveDir) && !$HARD_DELETE) {
    if ($APPLY) mkdir($archiveDir, 0750, true);
}

foreach ($orphans as $t) {
    $uid  = $t['uid'];
    $link = $t['link'];
    $real = $t['real'];
    $type = strtoupper($t['type']);

    printf("Orphan [%-20s] type=%-4s path=%s\n", $uid, $type, $real);

    // リンク運用: link 削除
    if ($t['type'] === 'link') {
        if ($APPLY) {
            @unlink($link)
                ? $stats['unlink']++
                : fwrite(STDERR, "[ERROR] unlink失敗: {$link}\n");
        } else {
            echo "  [DRY] unlink {$link}\n";
        }
    }

    // 実体を削除またはアーカイブ
    if (!str_starts_with($real, $homeRoot.'/')) continue;

    if ($APPLY) {
        if ($HARD_DELETE) {
            CliColor::printf("  [DEL] %s\n", $real, 'red');
            exec(sprintf('rm -rf %s', escapeshellarg($real)), $_, $code);
            if ($code === 0) $stats['delete']++;
            else fwrite(STDERR, "[ERROR] delete失敗: {$real}\n");
        } else {
            $dest = sprintf('%s/%s_%s', $archiveDir, basename($real), date('Ymd_His'));
            CliColor::printf("  [ARC] %s -> %s\n", $real, $dest, 'yellow');
            exec(sprintf('mv %s %s', escapeshellarg($real), escapeshellarg($dest)), $_, $code);
            if ($code === 0) $stats['archive']++;
            else fwrite(STDERR, "[ERROR] mv失敗: {$real}\n");
        }
    } else {
        echo "  [DRY] would " . ($HARD_DELETE ? "DELETE" : "ARCHIVE") . " {$real}\n";
    }
}

// ===============================================================
// 結果表示
// ===============================================================
echo "\n=== RESULT SUMMARY ===\n";
printf(" Archived : %d\n", $stats['archive']);
printf(" Deleted  : %d\n", $stats['delete']);
printf(" Unlinked : %d\n", $stats['unlink']);
printf(" Total    : %d\n", array_sum($stats));
echo "========================\n";
echo $APPLY ? "[DONE] EXECUTED.\n" : "[DONE] (dry-run)\n";
exit(0);


