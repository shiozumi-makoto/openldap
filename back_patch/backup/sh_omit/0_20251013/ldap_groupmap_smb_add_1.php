#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_groupmap_smb_add.php (using project libs)
 * posixGroup → Samba groupmap 反映（自動登録）
 *
 * 環境変数:
 *   LDAP_URL / LDAP_URI / LDAPURI
 *   FALLBACK_LDAPS_URL                  例: ldaps://ovs-012.e-smile.local
 *   BASE_DN / LDAP_BASE_DN              例: dc=e-smile,dc=ne,dc=jp
 *   GROUPS_OU                           例: ou=Groups,${BASE_DN}
 *   BIND_DN / BIND_PW / LDAP_ADMIN_PW
 *   DOM_SID_PREFIX                      例: S-1-5-21-...
 *
 * オプション:
 *   --confirm        実行（未指定はDRY-RUN）
 *   --uri=...        接続URIを明示（未指定はEnv）
 *   --base-dn=...    ベースDN
 *   --groups-ou=...  グループOU DN（未指定は "ou=Groups,${BASE_DN}"）
 *   --debug          例外時にスタックトレースを出力
 */

require_once __DIR__ . '/autoload.php';

// ★Lib Ldap 共通ライブラリ
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Ldap\MemberUid;

// ========== ログ関数（色は存在すれば使用） ==========
$B = class_exists(CliColor::class) ? [CliColor::class,'bold']    : fn(string $s)=>$s;
$G = class_exists(CliColor::class) ? [CliColor::class,'green']   : fn(string $s)=>$s;
$Y = class_exists(CliColor::class) ? [CliColor::class,'yellow']  : fn(string $s)=>$s;
$R = class_exists(CliColor::class) ? [CliColor::class,'red']     : fn(string $s)=>$s;
$C = class_exists(CliColor::class) ? [CliColor::class,'cyan']    : fn(string $s)=>$s;

$log = function(string $m): void { fwrite(STDOUT, $m.(str_ends_with($m,"\n")?'':"\n")); };
$err = function(string $m): void { fwrite(STDERR, $m.(str_ends_with($m,"\n")?'':"\n")); };

// ========== オプション解析 ==========
$opt = getopt('', ['confirm','uri:','base-dn:','groups-ou:','debug']);
$confirm   = isset($opt['confirm']);
$debug     = isset($opt['debug']);
$uriOpt    = $opt['uri']        ?? null;
$baseDnOpt = $opt['base-dn']    ?? null;
$groupsOu  = $opt['groups-ou']  ?? null;

// Env から既定を取得（Tools\Ldap\Env を使用）
$uri      = $uriOpt   ?? Env::first(['LDAP_URL','LDAP_URI','LDAPURI'], 'ldaps://ovs-012.e-smile.local');
$baseDn   = $baseDnOpt?? Env::first(['BASE_DN','LDAP_BASE_DN'], 'dc=e-smile,dc=ne,dc=jp');
$groupsDn = $groupsOu ?: Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$fallback = Env::get('FALLBACK_LDAPS_URL', null, 'ldaps://ovs-012.e-smile.local');
$domSid   = Env::get('DOM_SID_PREFIX'); // いまは未使用（将来の拡張向け）

// Connection に既定URIを渡しておく（任意）
try { Connection::init($uri); } catch (\Throwable $e) { /* noop: 環境から拾える */ }

// ========== サマリー表示 ==========
$haveNet = (trim((string)@shell_exec('command -v net')) !== '');
$log($B("=== groupmap START ==="));
$log(sprintf(
    "HOST=%s GROUPS_DN=%s CONFIRM=%s HAVE_NET=%s",
    gethostname(),
    $groupsDn,
    $confirm ? $G('YES') : $Y('NO'),
    $haveNet ? $G('YES') : $Y('NO')
));

if (!$haveNet) {
    $log($Y("[SKIP] 'net' command not found; skipping groupmap"));
    $log($B("=== groupmap DONE ==="));
    exit(0);
}

// ========== 関数群 ==========
/**
 * 既存 groupmap を取得（'net groupmap list' をパース）
 * 例: "users (S-1-5-21-...)-> ...", "users -> ..."
 */
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

/** LDAP から posixGroup 一覧を取得 */
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

/** 実行（またはDRY表示） */
$doGroupmapAdd = function (string $cn, ?string $unixGroup, bool $confirm) use ($log): array {
    // net groupmap add ntgroup="CN" unixgroup="CN" type=domain
    $cmd = ['net','groupmap','add','ntgroup='.$cn];
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

// ========== メイン ==========
try {
    // 1) 接続 & 認証（ldapi は connect 内で EXTERNAL、非 ldapi は bind が必要）
    try {
        $ds = Connection::connect($uri);
        Connection::bind($ds, null, null, $uri);
    } catch (\Throwable $e) {
        $log($Y("[INFO] primary connect failed; fallback to {$fallback}"));
        $ds = Connection::connect($fallback);
        Connection::bind($ds, null, null, $fallback);
    }

    // 2) 現状の groupmap と posixGroup を取得
    $existing = $existingGroupmap();
    $groups   = $fetchPosixGroups($ds, $groupsDn);

    // 3) 差分適用
    $addPlan=0; $ok=0; $keep=0; $errc=0;

//	print_r($groups);
//	exit;

    foreach ($groups as $g) {
        $cn = $g['cn'];
        if (isset($existing[$cn])) { $keep++; continue; }

        $addPlan++;
        $res = $doGroupmapAdd($cn, $cn, $confirm);
        if ($confirm) {
            if (($res['rc'] ?? 1) === 0) {
                $ok++;
            } else {
                $errc++;
                $log($R(sprintf(
                    "[ERR ] groupmap add ntgroup=%s rc=%d out=%s",
                    $cn, $res['rc'] ?? -1, trim((string)($res['out'] ?? ''))
                )));
            }
        }
    }

    // 4) サマリー
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


