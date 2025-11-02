#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/ldap_cli_uri_switch.inc.php';

/**
 * ldap_groupmap_smb_add.php
 * --------------------------
 * posixGroup → Samba groupmap 反映ツール（自動登録／強制更新／初期化）
 *
 * モード:
 *   --confirm     実際に変更を反映（未指定時はDRY-RUN）
 *   --force       既存 groupmap に対しても上書き (modify)
 *   --init        既存 groupmap を全削除して再登録
 *
 * 環境変数:
 *   LDAP_URL / LDAP_URI / LDAPURI
 *   FALLBACK_LDAPS_URL               (例: ldaps://ovs-012.e-smile.local)
 *   BASE_DN / LDAP_BASE_DN
 *   GROUPS_OU
 *   BIND_DN / BIND_PW / LDAP_ADMIN_PW
 *   DOM_SID_PREFIX                   (例: S-1-5-21-...)
 */

require_once __DIR__ . '/autoload.php';

// ★Lib Ldap 共通ライブラリ
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;

// ===== ログ関数（色対応） =====
$B = class_exists(CliColor::class) ? [CliColor::class,'bold']    : fn(string $s)=>$s;
$G = class_exists(CliColor::class) ? [CliColor::class,'green']   : fn(string $s)=>$s;
$Y = class_exists(CliColor::class) ? [CliColor::class,'yellow']  : fn(string $s)=>$s;
$R = class_exists(CliColor::class) ? [CliColor::class,'red']     : fn(string $s)=>$s;
$C = class_exists(CliColor::class) ? [CliColor::class,'cyan']    : fn(string $s)=>$s;

$log = fn(string $m) => fwrite(STDOUT, $m.(str_ends_with($m,"\n") ? '' : "\n"));
$err = fn(string $m) => fwrite(STDERR, $m.(str_ends_with($m,"\n") ? '' : "\n"));

// ===== オプション解析 =====
$opt = getopt('', ['confirm','force','init','uri:','base-dn:','groups-ou:','debug']);
$confirm   = isset($opt['confirm']);
$force     = isset($opt['force']);
$init      = isset($opt['init']);
$debug     = isset($opt['debug']);
$uriOpt    = $opt['uri']        ?? null;
$baseDnOpt = $opt['base-dn']    ?? null;
$groupsOu  = $opt['groups-ou']  ?? null;

// ===== 環境変数から既定を取得 =====
$uri      = $uriOpt   ?? Env::first(['LDAP_URL','LDAP_URI','LDAPURI'], 'ldaps://ovs-012.e-smile.local');
$baseDn   = $baseDnOpt?? Env::first(['BASE_DN','LDAP_BASE_DN'], 'dc=e-smile,dc=ne,dc=jp');
$groupsDn = $groupsOu ?: Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$fallback = Env::get('FALLBACK_LDAPS_URL', null, 'ldaps://ovs-012.e-smile.local');

// ===== 接続設定初期化 =====
try { Connection::init($uri); } catch (\Throwable $e) { /* noop */ }

// ===== 開始表示 =====
$haveNet = (trim((string)@shell_exec('command -v net')) !== '');
$log($B("=== groupmap START ==="));
$log(sprintf(
    "HOST=%s GROUPS_DN=%s CONFIRM=%s FORCE=%s INIT=%s HAVE_NET=%s",
    gethostname(),
    $groupsDn,
    $confirm ? $G('YES') : $Y('NO'),
    $force   ? $G('YES') : $Y('NO'),
    $init    ? $G('YES') : $Y('NO'),
    $haveNet ? $G('YES') : $Y('NO')
));

if (!$haveNet) {
    $log($Y("[SKIP] 'net' command not found; skipping groupmap"));
    $log($B("=== groupmap DONE ==="));
    exit(0);
}

// ===== 関数群 =====
/** 現在の Samba groupmap 一覧を取得 */
$existingGroupmap = function (): array {
    $result = [];
    @exec('net groupmap list 2>/dev/null', $lines, $rc);
    if ($rc !== 0 || empty($lines)) return $result;
    foreach ($lines as $line) {
        if (preg_match('/^(.+?)\s+\(S-1-5-[^)]+\)\s*->/i', $line, $m)) {
            $result[trim($m[1])] = true;
        } elseif (preg_match('/^(.+?)\s*->/i', $line, $m)) {
            $result[trim($m[1])] = true;
        }
    }
    return $result;
};

/** LDAP から posixGroup を取得 */
$fetchPosixGroups = function (\LDAP\Connection $ds, string $groupsDn): array {
    $attrs = ['cn','gidNumber'];
    $sr = @ldap_search($ds, $groupsDn, '(objectClass=posixGroup)', $attrs);
    if ($sr === false) throw new \RuntimeException('search groups failed: '.ldap_error($ds));
    $entries = ldap_get_entries($ds, $sr);
    $list = [];
    for ($i=0; $i < $entries['count']; $i++) {
        $e = $entries[$i];
        if (empty($e['cn'][0])) continue;
        $list[] = [
            'dn'        => $e['dn'],
            'cn'        => $e['cn'][0],
            'gidNumber' => $e['gidnumber'][0] ?? null,
        ];
    }
    return $list;
};

/** groupmap コマンド実行 */
$doGroupmap = function (string $action, string $cn, ?string $unixGroup, bool $confirm) use ($log): array {
    $cmd = ['net','groupmap',$action,'ntgroup='.$cn];
    if ($unixGroup !== null && $unixGroup !== '') $cmd[] = 'unixgroup='.$unixGroup;
    $cmd[] = 'type=domain';
    $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));

    if (!$confirm) {
        $log("[DRY] $cmdStr");
        return ['cmd'=>$cmdStr, 'rc'=>null, 'out'=>null];
    }
    @exec($cmdStr.' 2>&1', $out, $rc);
    return ['cmd'=>$cmdStr, 'rc'=>$rc, 'out'=>implode("\n",(array)$out)];
};

// ===== メイン処理 =====
try {
    // 1) LDAP接続
    try {
        $ds = Connection::connect($uri);
        Connection::bind($ds, null, null, $uri);
    } catch (\Throwable $e) {
        $log($Y("[INFO] primary connect failed; fallback to {$fallback}"));
        $ds = Connection::connect($fallback);
        Connection::bind($ds, null, null, $fallback);
    }

    // 2) groupmap取得
    $existing = $existingGroupmap();
    $groups   = $fetchPosixGroups($ds, $groupsDn);

    // 3) INIT: 既存削除
    if ($init && $confirm) {
        $log($Y("[INIT] Removing all existing groupmaps..."));
        foreach (array_keys($existing) as $cn) {
            $cmd = 'net groupmap delete ntgroup='.escapeshellarg($cn).' 2>&1';
            exec($cmd, $out, $rc);
            if ($rc === 0) $log($G("[DEL] $cn"));
            else $err($R("[ERR] failed to delete $cn"));
        }
        $existing = []; // リセット
    }

//	print_r($groups);
//	exit;

    // 4) 追加または更新
    $addPlan=0; $ok=0; $keep=0; $errc=0;
    foreach ($groups as $g) {
        $cn = $g['cn'];
        $exists = isset($existing[$cn]);
        if ($exists && !$force) { $keep++; continue; }

        $action = $exists && $force ? 'modify' : 'add';
        $addPlan++;
        $res = $doGroupmap($action, $cn, $cn, $confirm);

        if ($confirm) {
            if (($res['rc'] ?? 1) === 0) {
                $ok++;
                $log($G(sprintf("[OK  ] %s ntgroup=%s", strtoupper($action), $cn)));
            } else {
                $errc++;
                $log($R(sprintf("[ERR ] %s ntgroup=%s rc=%d out=%s",
                    strtoupper($action), $cn, $res['rc'] ?? -1, trim((string)($res['out'] ?? ''))
                )));
            }
        }
    }

    // 5) 結果表示
    $summary = sprintf("SUMMARY: planned=%d ok=%d keep=%d err=%d (uri=%s)",
        $addPlan, $ok, $keep, $errc, $uri);
    $log($B($summary));
    $log($B("=== groupmap DONE ==="));

    Connection::close($ds);
    exit($errc ? 2 : 0);

} catch (\RuntimeException $e) {
    $err($R("[ERROR] ".$e->getMessage()));
    if ($debug) $err($e->getTraceAsString());
    exit(2);
} catch (\Throwable $e) {
    $err($R("[FATAL] ".$e->getMessage()));
    if ($debug) $err($e->getTraceAsString());
    exit(70);
}


