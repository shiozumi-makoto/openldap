#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_memberuid_users_group.php
 * refactored: 2025-10-22
 *
 * 概要:
 *  - ou=Groups 配下の posixGroup に対して memberUid を同期
 *  - 対象グループ:
 *      * "users" … People OU 配下の全 uid（posixAccount）を基本とする
 *      * 役職クラス群（adm-cls / dir-cls / mgr-cls / mgs-cls / stf-cls / ent-cls / tmp-cls / err-cls）
 *        → user の employeeType（例: "adm-cls 1"）を解析して配属
 *      * 事業系（例: esmile-dev など）
 *        → primary gidNumber をキーに People を収集（GroupDef::BIZ_MAP があればそれも利用）
 *  - グループが存在しない場合、--init 指定時に
 *        ldap_level_groups_sync.php --init-group --group=<name> --confirm
 *    を自動実行して作成（GroupDef に基づく / 既定の作成規則）
 *
 * 主なオプション:
 *  --help             ヘルプ
 *  --confirm          変更を適用（未指定なら DRY-RUN）
 *  --init             未存在グループを自動作成（ldap_level_groups_sync.php 呼び出し）
 *  --group=NAME[,..]  対象グループを限定（例: users,adm-cls,esmile-dev）
 *                      省略時は users + クラス群（*-cls）
 *  --list             差分表示を詳細に（want/have/summary）
 *  --ldapi            接続URIを ldapi 既定に（未指定時は LDAP_URI/LDAP_URL/LDAPURI を参照）
 *  --uri=URI          LDAP 接続URIを明示（例: ldaps://ovs-012.e-smile.local）
 *  --base-dn=DN       ベースDN（既定: BIND_DN から自動算出）
 *  --people-ou=DN     People OU（例: ou=Users,<BASE_DN>）自動検出も対応
 *  --groups-ou=DN     Groups OU（例: ou=Groups,<BASE_DN>）
 *
 * 依存:
 *  - ext-ldap
 *  - （任意）Tools\Ldap\Support\GroupDef, Tools\Ldap\Env, Tools\Lib\CliColor
 *
 * 例:
 *  php ldap_memberuid_users_group.php --confirm --group=users
 *  php ldap_memberuid_users_group.php --confirm --init --group=err-cls --ldapi
 *  php ldap_memberuid_users_group.php --list
 *  php ldap_memberuid_users_group.php --ldapi --list --group=esmile-dev
 *  php ldap_memberuid_users_group.php --ldapi --confirm --group=esmile-dev
 */

//--------------------------------------------------------------
// オートロード（存在すれば活用） & 共通ヘルプ
//--------------------------------------------------------------
@require_once __DIR__ . '/autoload.php';
// --- unify CLI LDAP URI handling ---
@require_once __DIR__ . '/inc/ldap_cli_uri_switch.inc.php';
@require_once __DIR__ . '/inc/cli_help_connect.inc.php';

use Tools\Ldap\Support\GroupDef;
use Tools\Ldap\Env;
use Tools\Lib\CliColor;

//--------------------------------------------------------------
// 端末カラー（存在すれば使用）
//--------------------------------------------------------------
$isColor = class_exists(CliColor::class);
$C = [
    'bold'   => $isColor ? [CliColor::class,'bold']      : function($s){return $s;},
    'green'  => $isColor ? [CliColor::class,'green']     : function($s){return $s;},
    'yellow' => $isColor ? [CliColor::class,'yellow']    : function($s){return $s;},
    'red'    => $isColor ? [CliColor::class,'red']       : function($s){return $s;},
    'cyan'   => $isColor ? [CliColor::class,'cyan']      : function($s){return $s;},
    'bcyan'  => $isColor ? [CliColor::class,'boldCyan']  : function($s){return $s;},
    'bgreen' => $isColor ? [CliColor::class,'boldGreen'] : function($s){return $s;},
];

function log_info(string $m){ global $C; echo ($C['green'])("[INFO] ").$m."\n"; }
function log_warn(string $m){ global $C; echo ($C['yellow'])("[WARN] ").$m."\n"; }
function log_err (string $m){ global $C; fwrite(STDERR, ($C['red'])("[ERROR] ").$m."\n"); }
function log_step(string $m){ global $C; echo ($C['bcyan'])($m)."\n"; }

//--------------------------------------------------------------
// 環境変数ヘルパ
//--------------------------------------------------------------
function envv(string $k, ?string $def=null): ?string {
    if (class_exists(Env::class) && method_exists(Env::class,'get')) {
        $v=Env::get($k); if ($v!==null && $v!=='') return $v;
    }
    $v=getenv($k); if ($v!==false && $v!=='') return $v;
    return $def;
}

// PHP7 互換 polyfill
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

//--------------------------------------------------------------
// CLI 解析
//--------------------------------------------------------------
$options = getopt('', [
    'help',
    'confirm',
    'init',
    'group::',
    'list',
    'ldapi',
    'uri::',
    'base-dn::',
    'people-ou::',
    'groups-ou::',
]);

if (isset($options['help'])) {
    $bin = basename(__FILE__);
    echo <<<HELP
{$bin} - Sync memberUid for posixGroup under ou=Groups

Usage:
  php {$bin} [--confirm] [--init] [--group=users,adm-cls,esmile-dev,...] [--list]
             [--ldapi | --uri=URI] [--base-dn=DN] [--people-ou=DN] [--groups-ou=DN] [--help]

Options:
  --help         ヘルプを表示
  --confirm      変更を適用（未指定は DRY-RUN）
  --init         未存在グループを自動作成（ldap_level_groups_sync.php 呼び出し）
  --group=...    対象グループを限定（カンマ区切り）。省略時は users + クラス群（*-cls）
  --list         want/have の詳細を表示
  --ldapi        URI 未指定時、ldapi を既定に
  --uri=URI      LDAP 接続URIを明示（例: ldaps://ovs-012.e-smile.local）
  --base-dn=DN   ベースDN（既定: BIND_DN から推定）
  --people-ou=DN People OU（自動検出対応）
  --groups-ou=DN Groups OU（既定: ou=Groups,<BASE_DN>）

Env:
  BIND_DN / BIND_PW / LDAP_URL / LDAP_URI / LDAPURI
  BASE_DN / PEOPLE_OU / GROUPS_OU

HELP;
    if (function_exists('cli_connect_samples')) {
        echo cli_connect_samples();
    }
    exit(0);
}

$CONFIRM   = isset($options['confirm']);
$DO_INIT   = isset($options['init']);
$LIST      = isset($options['list']);

$URI_OPT   = $options['uri'] ?? null;
$LDAP_URL  = getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: ($URI_OPT ?: null);
// Backward-compat: allow --ldapi to force default socket if nothing chosen
if (!$LDAP_URL && isset($options['ldapi'])) {
    $LDAP_URL = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
}

$BIND_DN   = envv('BIND_DN', 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
$BIND_PW   = envv('BIND_PW', '');

$BASE_DN   = $options['base-dn'] ?? envv('BASE_DN', null);
if (!$BASE_DN) {
    // BIND_DN からベースを推定
    $BASE_DN = preg_replace('/^[^,]+,/', '', $BIND_DN);
}

$PEOPLE_OU = $options['people-ou'] ?? envv('PEOPLE_OU', '');
$GROUPS_OU = $options['groups-ou'] ?? envv('GROUPS_OU', "ou=Groups,{$BASE_DN}");

// 対象グループ
$groupArg = $options['group'] ?? null;
if ($groupArg) {
    $TARGET_GROUPS = array_values(array_filter(array_map('trim', explode(',', $groupArg)), function($s){return $s!=='';}));
} else {
    // 既定は: users + 職位クラス。※ BIZ は必要時に --group=esmile-dev 等で指定
    $TARGET_GROUPS = ['users','adm-cls','dir-cls','mgr-cls','mgs-cls','stf-cls','ent-cls','tmp-cls','err-cls'];
}

//--------------------------------------------------------------
// 実行見出し
//--------------------------------------------------------------
echo "\n";
echo ($C['bcyan'])("=== memberUid sync START ===\n");
printf("URI     : %s\n", $LDAP_URL);
printf("BASE_DN : %s\n", $BASE_DN);
printf("PEOPLE  : %s\n", $PEOPLE_OU ? $PEOPLE_OU : '(auto)');
printf("GROUPS  : %s\n", $GROUPS_OU);
printf("CONFIRM : %s\n", $CONFIRM ? 'YES (apply)' : 'NO (DRY-RUN)');
printf("INIT    : %s\n", $DO_INIT ? 'YES (auto-create missing groups)' : 'NO');
printf("GROUPS# : %s\n", ($C['bcyan'])(implode(',', $TARGET_GROUPS)));
echo "----------------------------------------------\n";

//--------------------------------------------------------------
// LDAP 接続
//--------------------------------------------------------------
$link = @ldap_connect($LDAP_URL);
if (!$link) { log_err("ldap_connect failed: {$LDAP_URL}"); exit(1); }
@ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
@ldap_set_option($link, LDAP_OPT_REFERRALS, 0);

$wantExternal = str_starts_with($LDAP_URL, 'ldapi://');
$bindOk = false;
if ($wantExternal && function_exists('ldap_sasl_bind')) {
    // EXTERNAL bind（ldapi）
    putenv('LDAPTLS_REQCERT=never');
    $bindOk = @ldap_sasl_bind($link, null, null, 'EXTERNAL');
} else {
    $bindOk = @ldap_bind($link, $BIND_DN, $BIND_PW);
}
if (!$bindOk) { log_err("ldap_bind failed: ".(function_exists('ldap_error')?ldap_error($link):'unknown')); exit(1); }

//--------------------------------------------------------------
// OU 自動検出（People）
//--------------------------------------------------------------
if ($PEOPLE_OU === '' || $PEOPLE_OU === null) {
    foreach (["ou=Users,{$BASE_DN}","ou=People,{$BASE_DN}"] as $cand) {
        $sr = @ldap_search($link, $cand, '(objectClass=organizationalUnit)', ['ou']);
        if ($sr) {
            $e = @ldap_get_entries($link,$sr);
            if (is_array($e) && (($e['count'] ?? 0) > 0)) { $PEOPLE_OU = $cand; break; }
        }
    }
    if (!$PEOPLE_OU) { log_err("People OU を自動検出できません。--people-ou で明示してください。"); exit(1); }
    log_info("People OU: {$PEOPLE_OU}（自動検出）");
} else {
    log_info("People OU: {$PEOPLE_OU}（指定）");
}

//--------------------------------------------------------------
// ヘルパ
//--------------------------------------------------------------
/** DN の存在確認（read で確認） */
function ldap_entry_exists($link, string $dn): bool {
    $sr = @ldap_read($link, $dn, '(objectClass=*)', ['dn']);
    if ($sr === false) return false;
    $e = @ldap_get_entries($link,$sr);
    return is_array($e) && (($e['count'] ?? 0) > 0);
}

/** Groups OU の cn=xxx entry DN */
function group_dn(string $cn, string $groupsOu): string {
    return "cn={$cn},{$groupsOu}";
}

/** 現在の memberUid 一式を取得（返り値: array<string>） */
function get_group_member_uids($link, string $groupDn): array {
    $sr = @ldap_read($link, $groupDn, '(objectClass=posixGroup)', ['memberUid']);
    if (!$sr) return [];
    $e = @ldap_get_entries($link,$sr);
    if (!is_array($e) || ($e['count'] ?? 0) === 0) return [];
    $arr = [];
    if (isset($e[0]['memberuid'])) {
        $n = (int)$e[0]['memberuid']['count'];
        for ($i=0;$i<$n;$i++) { $arr[] = (string)$e[0]['memberuid'][$i]; }
    }
    sort($arr, SORT_STRING);
    return $arr;
}

/** posixAccount な People の uid を取得（返り値: array<uid>） */
function get_all_people_uids($link, string $peopleOu): array {
    $sr = @ldap_search($link, $peopleOu, '(&(objectClass=posixAccount)(uid=*))', ['uid']);
    if (!$sr) return [];
    $e = @ldap_get_entries($link,$sr);
    $ret = [];
    if (is_array($e)) {
        $cnt = (int)($e['count'] ?? 0);
        for ($i=0;$i<$cnt;$i++) {
            if (!isset($e[$i]['uid'][0])) continue;
            $ret[] = (string)$e[$i]['uid'][0];
        }
    }
    $ret = array_values(array_unique($ret));
    sort($ret, SORT_STRING);
    return $ret;
}

/** People の employeeType を見て、cls → [uids] のマップを作る */
function get_class_members_from_people($link, string $peopleOu): array {
    // 主要クラス名（GroupDef からも拾えるが、固定で網羅しておく）
    $classes = ['adm-cls','dir-cls','mgr-cls','mgs-cls','stf-cls','ent-cls','tmp-cls','err-cls'];
    $want = []; foreach ($classes as $c) { $want[$c]=[]; }

    $sr = @ldap_search($link, $peopleOu, '(uid=*)', ['uid','employeeType','sn','givenName']);
    if (!$sr) return $want;
    $e = @ldap_get_entries($link,$sr);
    $cnt = (int)($e['count'] ?? 0);

    for ($i=0;$i<$cnt;$i++) {
        $uid = isset($e[$i]['uid'][0]) ? (string)$e[$i]['uid'][0] : null;
        if ($uid === null || $uid==='') continue;

        $etype = isset($e[$i]['employeetype'][0]) ? (string)$e[$i]['employeetype'][0] : '';
        // 形式: "<cls> <level>" 例: "adm-cls 1"
        $cls = null;
        if ($etype !== '') {
            if (preg_match('/^\s*([a-z\-]+)\s+(\d+)\s*$/i', $etype, $m)) {
                $cls = strtolower($m[1]); // $levelId = (int)$m[2]; // 今は未使用
            } else {
                // Tools\Ldap\Support\GroupDef::fromEmployeeType があれば活用
                if (class_exists(GroupDef::class) && method_exists(GroupDef::class,'fromEmployeeType')) {
                    $r = GroupDef::fromEmployeeType($etype);
                    if (is_array($r) && isset($r['name'])) { $cls = (string)$r['name']; }
                }
            }
        }
        if (!$cls) {
            // levelId が拾えない・未知形式の場合は err-cls 扱いに
            $cls = 'err-cls';
        }

        if (!array_key_exists($cls, $want)) $want[$cls]=[];
        $want[$cls][] = $uid;
    }

    foreach ($want as $k=>$arr) {
        $arr = array_values(array_unique($arr));
        sort($arr, SORT_STRING);
        $want[$k] = $arr;
    }
    return $want;
}

/** 差分を計算 */
function diff_members(array $want, array $have): array {
    $toAdd = array_values(array_diff($want, $have));
    $toDel = array_values(array_diff($have, $want));
    sort($toAdd,SORT_STRING); sort($toDel,SORT_STRING);
    return [$toAdd,$toDel];
}

/** グループDNから gidNumber を読む（無ければ null） */
function get_group_gid($link, string $groupDn): ?int {
    $sr = @ldap_read($link, $groupDn, '(objectClass=posixGroup)', ['gidNumber']);
    if (!$sr) return null;
    $e = @ldap_get_entries($link,$sr);
    if (!is_array($e) || ($e['count'] ?? 0) === 0) return null;
    if (!isset($e[0]['gidnumber'][0])) return null;
    return (int)$e[0]['gidnumber'][0];
}

/** People から primary gidNumber=GID の uid を収集 */
function get_people_uids_by_primary_gid($link, string $peopleOu, int $gid): array {
    $filter = sprintf('(&(objectClass=posixAccount)(gidNumber=%d)(uid=*))', $gid);
    $sr = @ldap_search($link, $peopleOu, $filter, ['uid']);
    if (!$sr) return [];
    $e = @ldap_get_entries($link,$sr);
    $ret = [];
    if (is_array($e)) {
        $cnt = (int)($e['count'] ?? 0);
        for ($i=0;$i<$cnt;$i++) {
            if (!isset($e[$i]['uid'][0])) continue;
            $ret[] = (string)$e[$i]['uid'][0];
        }
    }
    $ret = array_values(array_unique($ret));
    sort($ret, SORT_STRING);
    return $ret;
}

//--------------------------------------------------------------
// 望ましいメンバー集合を構築
//--------------------------------------------------------------
$wantMatrix = []; // groupName => [uids]

// users グループ
if (in_array('users',$TARGET_GROUPS,true)) {
    $wantMatrix['users'] = get_all_people_uids($link,$PEOPLE_OU);
}

// 事業系(BIZ) 対象（users と cls を除外して、残りをBIZ候補とみなす）
$KNOWN_CLS = ['adm-cls','dir-cls','mgr-cls','mgs-cls','stf-cls','ent-cls','tmp-cls','err-cls'];
$BIZ_TARGETS = array_values(array_diff($TARGET_GROUPS, array_merge(['users'], $KNOWN_CLS)));

if ($BIZ_TARGETS) {
    foreach ($BIZ_TARGETS as $biz) {
        $bizDn = group_dn($biz, $GROUPS_OU);

        // gidNumber を決める：GroupDef::BIZ_MAP → グループDNから読み取り の順で判定
        $gid = null;
        if (class_exists(GroupDef::class) && defined(GroupDef::class.'::BIZ_MAP')) {
            $map = constant(GroupDef::class.'::BIZ_MAP');
            if (is_array($map) && isset($map[$biz])) {
                $gid = (int)$map[$biz];
            }
        }
        if ($gid === null) {
            // グループが無いと gid も読めないので、--init 指定なら先に作成を試みる
            if (!ldap_entry_exists($link,$bizDn) && $DO_INIT) {
                log_warn("Group '{$biz}' not found. Creating via ldap_level_groups_sync.php ...");
                $cmd = sprintf(
                    'php %s/ldap_level_groups_sync.php --init-group --group=%s --confirm --ldap-uri=%s',
                    escapeshellarg(__DIR__),
                    escapeshellarg($biz),
                    escapeshellarg($LDAP_URL)
                );
                $out = shell_exec($cmd . ' 2>&1');
                if ($out !== null && trim($out)!=='') {
                    foreach (explode("\n", trim($out)) as $line) log_info($line);
                }
            }
            // 作成済/既存であれば gidNumber を読む
            if (ldap_entry_exists($link,$bizDn)) {
                $gid = get_group_gid($link, $bizDn);
            }
        }

        if ($gid === null) {
            log_warn("skip: '{$biz}' の gidNumber が特定できません（--init で作成 or GroupDef::BIZ_MAP 定義を推奨）");
            continue;
        }

        // primary gidNumber=gid の People を memberUid として集計
        $wantMatrix[$biz] = get_people_uids_by_primary_gid($link, $PEOPLE_OU, $gid);
    }
}

// クラス群（*-cls）を要求された場合
$CLS_TARGETS = array_values(array_intersect($KNOWN_CLS, $TARGET_GROUPS));
if ($CLS_TARGETS) {
    $clsWantAll = get_class_members_from_people($link,$PEOPLE_OU);
    foreach ($CLS_TARGETS as $cls) {
        $wantMatrix[$cls] = $clsWantAll[$cls] ?? [];
    }
}

//--------------------------------------------------------------
// 同期（グループごと）
//--------------------------------------------------------------
$total_add_all=0; $total_del_all=0; $total_groups=count($wantMatrix);

foreach ($wantMatrix as $grp=>$wantUids) {
    $groupDn = group_dn($grp, $GROUPS_OU);

    // === ここで未存在グループの自動作成（--init 指定時のみ） ===
    if (!ldap_entry_exists($link,$groupDn)) {
        if ($DO_INIT) {
            log_warn("Group '{$grp}' not found. Creating via ldap_level_groups_sync.php ...");
            $cmd = sprintf(
                'php %s/ldap_level_groups_sync.php --init-group --group=%s --confirm --ldap-uri=%s',
                escapeshellarg(__DIR__),
                escapeshellarg($grp),
                escapeshellarg($LDAP_URL)
            );
            $out = shell_exec($cmd . ' 2>&1');
            if ($out !== null && trim($out)!=='') {
                foreach (explode("\n", trim($out)) as $line) log_info($line);
            }
            // 再確認
            if (!ldap_entry_exists($link,$groupDn)) {
                log_err("Failed to create group '{$grp}' via ldap_level_groups_sync.php. skip.");
                continue;
            }
        } else {
            log_warn("skip: group '{$grp}' not found (use --init to auto-create)");
            continue;
        }
    }

    $haveUids = get_group_member_uids($link,$groupDn);
    [$toAdd,$toDel] = diff_members($wantUids,$haveUids);

    if ($LIST) {
        // want/have の一覧（多い場合は先頭数件だけ）
        $cut = 120; // 表示しすぎない
        $dispWant = $wantUids;
        $dispHave = $haveUids;

        echo ($C['bold'])("[LIST] GRP {$grp}  want(".count($wantUids)."):");
        if (count($dispWant)>0) echo ' '.implode(',', array_slice($dispWant,0,$cut)).(count($dispWant)>$cut?' ...':'');
        echo "\n";
        echo "[LIST] GRP {$grp}  have(".count($haveUids)."):";
        if (count($dispHave)>0) echo ' '.implode(',', array_slice($dispHave,0,$cut)).(count($dispHave)>$cut?' ...':'');
        echo "\n";
    }

    // want=0 のとき全削除は危険なのでスキップ
    $safeSkipDelete = (count($wantUids) === 0);

    $addCount = 0; $delCount = 0;

    // 追加
    if ($toAdd) {
        if ($CONFIRM) {
            // まとめて追加（memberUid は multi-valued）
            $entry = ['memberUid' => $toAdd];
            $ok = @ldap_mod_add($link, $groupDn, $entry);
            if (!$ok) {
                // 失敗時は1件ずつ
                foreach ($toAdd as $uid) {
                    @ldap_mod_add($link, $groupDn, ['memberUid'=>[$uid]]);
                }
            }
        }
        $addCount = count($toAdd);
    }

    // 削除
    if ($toDel && !$safeSkipDelete) {
        if ($CONFIRM) {
            $entry = ['memberUid' => $toDel];
            $ok = @ldap_mod_del($link, $groupDn, $entry);
            if (!$ok) {
                foreach ($toDel as $uid) {
                    @ldap_mod_del($link, $groupDn, ['memberUid'=>[$uid]]);
                }
            }
        }
        $delCount = count($toDel);
    } elseif ($toDel && $safeSkipDelete) {
        log_warn("[SAFE] skip delete on {$grp} because want=0");
        $toDel = []; // 表示上0に
    }

    // サマリ表示
    $applied = $CONFIRM ? '[applied]' : '[planned]';
    printf("[SUMMARY:GRP %-10s] add=%d del=%d %s\n", $grp, $addCount, $delCount, $applied);
    $total_add_all += $addCount; $total_del_all += $delCount;
}

//--------------------------------------------------------------
// users グループ全体の集計（users が対象に入っている場合）
//--------------------------------------------------------------
if (array_key_exists('users',$wantMatrix)) {
    $wantCnt = count($wantMatrix['users']);
    $haveCnt = 0;
    $usersDn = group_dn('users',$GROUPS_OU);
    if (ldap_entry_exists($link,$usersDn)) $haveCnt = count(get_group_member_uids($link,$usersDn));

    echo "\n=== memberUid 結果 ===\n";
    printf("  合計: %d, 追加予定/実績: %d, 参考: 登録済(概算): %d\n\n",
        $wantCnt, $total_add_all, max(0,$haveCnt-$total_del_all));
}

// 終了
if ($link) { @ldap_unbind($link); }
echo ($C['bgreen'])("[DONE] memberUid sync complete ").($CONFIRM?'(APPLIED)':'(DRY-RUN)')."\n";

