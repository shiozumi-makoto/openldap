#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_memberuid_users_group.php (final, no LdapUtil::infer* dependency)
 *
 * - ou=Groups 配下の posixGroup に対して memberUid を同期
 * - 対象:
 *     * users … People/Users OU 配下の全 uid（posixAccount）
 *     * 職位クラス … employeeType の先頭トークン（例: "adm-cls 1" → "adm-cls"）
 *
 * 依存ライブラリ（PSR-4, autoload.php 前提）:
 *   - Tools\Lib\Config
 *   - Tools\Lib\CliUtil
 *   - Tools\Lib\CliColor
 *   - Tools\Lib\LdapConnector
 *   - Tools\Ldap\Support\GroupDef
 */

require_once __DIR__ . '/autoload.php';

use Tools\Lib\Config;
use Tools\Lib\CliUtil;
use Tools\Lib\CliColor;
use Tools\Lib\LdapConnector;
use Tools\Ldap\Support\GroupDef;

//============================================================
// CLIオプション定義
//============================================================
$schema = [
    'help'      => ['cli'=>'help',     'type'=>'bool', 'default'=>false, 'desc'=>'このヘルプを表示'],
    'confirm'   => ['cli'=>'confirm',  'type'=>'bool', 'default'=>false, 'desc'=>'実行（未指定はDRY-RUN）'],
    'verbose'   => ['cli'=>'verbose',  'type'=>'bool', 'default'=>false, 'desc'=>'詳細ログを出力'],
    'list'      => ['cli'=>'list',     'type'=>'bool', 'default'=>false, 'desc'=>'diff内容（want/have）を一覧表示'],
    'init'      => ['cli'=>'init',     'type'=>'bool', 'default'=>false, 'desc'=>'未存在グループをposixGroupで作成'],

    'group'     => ['cli'=>'group',    'type'=>'string','default'=>null,  'desc'=>'対象グループ（カンマ区切り）省略時は users + cls 全部'],

    'safe_no_delete_when_empty' => [
        'cli'=>'safe-no-delete-empty', 'type'=>'bool', 'default'=>true,
        'desc'=>'want=0のクラスでは削除を抑止'
    ],

    // LDAP接続設定（LdapConnector互換）
    'uri'        => ['cli'=>'uri',       'type'=>'string', 'env'=>'LDAP_URI',       'default'=>null, 'desc'=>'LDAP URI'],
    'ldapi'      => ['cli'=>'ldapi',     'type'=>'bool',   'default'=>false,        'desc'=>'ldapiを使用'],
    'ldaps'      => ['cli'=>'ldaps',     'type'=>'bool',   'default'=>false,        'desc'=>'ldapsを使用'],
    'starttls'   => ['cli'=>'starttls',  'type'=>'bool',   'default'=>false,        'desc'=>'StartTLSを使用'],
    'bind_dn'    => ['cli'=>'bind-dn',   'type'=>'string', 'env'=>'LDAP_BIND_DN',   'default'=>null, 'desc'=>'Bind DN'],
    'bind_pass'  => ['cli'=>'bind-pass', 'type'=>'string', 'env'=>'LDAP_BIND_PASS', 'default'=>null, 'secret'=>true, 'desc'=>'Bind Password'],
    'base_dn'    => ['cli'=>'base-dn',   'type'=>'string', 'env'=>'LDAP_BASE_DN',   'default'=>null, 'desc'=>'Base DN'],
    'people_ou'  => ['cli'=>'people-ou', 'type'=>'string', 'env'=>'PEOPLE_OU',      'default'=>null, 'desc'=>'People/Users OU'],
    'groups_ou'  => ['cli'=>'groups-ou', 'type'=>'string', 'env'=>'GROUPS_OU',      'default'=>null, 'desc'=>'Groups OU'],
];

//============================================================
// 設定ロードとヘルプ
//============================================================
$cfg = Config::loadWithFile($argv, $schema, __DIR__ . '/inc/tools.conf');

if (!empty($cfg['help'])) {
    $prog = basename($_SERVER['argv'][0] ?? 'ldap_memberuid_users_group.php');
    echo CliUtil::buildHelp($schema, $prog, [
        'DRY-RUN' => "php {$prog} --ldapi --verbose",
        '実行'    => "php {$prog} --ldapi --confirm",
        '限定'    => "php {$prog} --ldapi --group=users,adm-cls",
        '作成込'  => "php {$prog} --ldapi --init --confirm",
    ]);
    exit(0);
}

//============================================================
// ロガー
//============================================================
$APPLY = !empty($cfg['confirm']);
$VERB  = !empty($cfg['verbose']);
$LIST  = !empty($cfg['list']);
$INIT  = !empty($cfg['init']);

$DBG = static function(string $m) use ($VERB): void { if ($VERB) echo "[DBG] {$m}\n"; };
$info = static fn(string $m)=>print CliColor::green("[INFO] {$m}\n");
$warn = static fn(string $m)=>print CliColor::yellow("[WARN] {$m}\n");
$err  = static fn(string $m)=>fwrite(STDERR, CliColor::red("[ERROR] {$m}\n"));

//============================================================
// 対象グループ
//============================================================
$allCls = ['adm-cls','dir-cls','mgr-cls','mgs-cls','stf-cls','ent-cls','tmp-cls','err-cls'];
$targets = !empty($cfg['group'])
    ? array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$cfg['group']))))
    : array_merge(['users'], $allCls);

//============================================================
// LDAP接続
//============================================================
/** @var \LDAP\Connection|resource|null $ds */
[$ds, $baseDn, $groupsDn, $uri] = LdapConnector::connect($cfg, $DBG);
if (!$ds) { $err('LDAP接続失敗'); exit(1); }

// Base DN（未指定なら自動推定）
if (!$baseDn) {
    $baseDn = infer_base_dn($ds);
    if (!$baseDn) { $err('BASE_DN を特定できません'); exit(1); }
    $info("BASE_DN: {$baseDn}（自動検出）");
} else {
    $info("BASE_DN: {$baseDn}");
}

// People/Users OU
$peopleOu = $cfg['people_ou'] ?: infer_people_ou($ds, $baseDn, $DBG);
if (!$peopleOu) { $err('People/Users OU を特定できません'); exit(1); }
$info("People OU: {$peopleOu}");

// Groups OU
$groupsOu = $cfg['groups_ou'] ?: ($groupsDn ?: "ou=Groups,{$baseDn}");
$info("Groups OU: {$groupsOu}");

//============================================================
// バナー
//============================================================
echo '  php ' . basename(__FILE__) . ' ' . CliUtil::argvString() . "\n\n";
printf("CONFIRM   : %s\n", $APPLY ? "YES (execute)" : "NO  (dry-run)");
printf("INIT      : %s\n", $INIT ? "YES" : "NO");
printf("VERBOSE   : %s\n", $VERB ? "YES" : "NO");
echo "----------------------------------------------\n";
printf("ldap_host : %s\n", (string)$uri);
printf("ldap_base : %s\n", $baseDn);
printf("people_ou : %s\n", $peopleOu);
printf("groups_ou : %s\n", $groupsOu);
echo "\n----------------------------------------------\n\n";

//============================================================
// 未存在グループの初期作成 (--init)
//============================================================
if ($INIT) {
    foreach ($targets as $g) {
        $dn = "cn={$g},{$groupsOu}";
        if (!ldap_entry_exists($ds, $dn)) {
            $warn("missing group: {$dn}");
            $attrs = [
                'objectClass' => ['top','posixGroup'],
                'cn'          => $g,
                'gidNumber'   => (string)(GroupDef::findGid($g) ?? 3000),
            ];
            if ($APPLY) {
                @ldap_add($ds, $dn, $attrs)
                    ? $info("created: {$dn}")
                    : $err("add failed: {$dn}");
            } else {
                $info("[DRY] create: {$dn}");
            }
        }
    }
}

//============================================================
// want（望ましい集合）
//============================================================
$want = [];
if (in_array('users', $targets, true)) {
    $want['users'] = get_all_people_uids($ds, $peopleOu, $DBG);
}
$clsTargets = array_values(array_intersect($allCls, $targets));
if ($clsTargets) {
    $byCls = get_class_members_from_people($ds, $peopleOu, $DBG);
    foreach ($clsTargets as $cls) $want[$cls] = $byCls[$cls] ?? [];
}

//============================================================
// diff → add/del
//============================================================
$total_add = $total_del = 0;

foreach ($targets as $g) {
    $dn = "cn={$g},{$groupsOu}";
    if (!ldap_entry_exists($ds, $dn)) {
        $warn("skip: group not found: {$dn}");
        continue;
    }
    $have = get_group_member_uids($ds, $dn, $DBG);
    $wantList = array_values(array_unique($want[$g] ?? []));
    sort($wantList, SORT_STRING);
    sort($have, SORT_STRING);

    $toAdd = array_values(array_diff($wantList, $have));
    $toDel = array_values(array_diff($have, $wantList));

    if ($g !== 'users' && empty($wantList) && !empty($cfg['safe_no_delete_when_empty']) && $toDel) {
        $warn("[SAFE] skip delete on {$g} because want=0");
        $toDel = [];
    }

    if ($LIST) {
        echo "---- {$g} want(".count($wantList).") vs have(".count($have).") ----\n";
        if ($toAdd) echo "  add: ".implode(' ', $toAdd)."\n";
        if ($toDel) echo "  del: ".implode(' ', $toDel)."\n";
    }

    $added = 0; $deleted = 0;
    if ($APPLY) {
        foreach ($toAdd as $uid) if (@ldap_mod_add($ds, $dn, ['memberUid'=>$uid])) $added++;
        foreach ($toDel as $uid) if (@ldap_mod_del($ds, $dn, ['memberUid'=>$uid])) $deleted++;
    } else {
        $added = count($toAdd);
        $deleted = count($toDel);
    }

    printf("[SUMMARY:%-7s] add=%d del=%d %s\n",
        $g, $added, $deleted, $APPLY ? '[applied]' : '[planned]'
    );

    $total_add += $added;
    $total_del += $deleted;
}

//============================================================
// サマリー
//============================================================
if (isset($want['users'])) {
    $wantCnt = count($want['users']);
    echo "\n=== memberUid 結果 ===\n";
    printf("  合計: %d, 新規追加: %d, 登録済: %d\n\n",
        $wantCnt, $total_add, max(0, $wantCnt - $total_add)
    );
}

@ldap_unbind($ds);
echo CliColor::boldGreen("[DONE] ") . "memberUid sync complete " . ($APPLY ? "(APPLIED)" : "(DRY-RUN)") . "\n";


//============================================================
// 内蔵ヘルパ（ネイティブLDAPで実装・LdapUtil無しでも動く）
//============================================================

/** BaseDN 推定（namingContexts を読む） */
function infer_base_dn($ds): ?string {
    $res = @ldap_read($ds, '', '(objectClass=*)', ['namingContexts']);
    if ($res) {
        $e = @ldap_get_entries($ds, $res);
        if (is_array($e) && (($e['count'] ?? 0) > 0)) {
            $vals = $e[0]['namingcontexts'] ?? $e[0]['namingContexts'] ?? null;
            if (is_array($vals) && (($vals['count'] ?? 0) > 0)) {
                // dc= を優先
                for ($i=0; $i < $vals['count']; $i++) {
                    $dn = (string)$vals[$i];
                    if (stripos($dn, 'dc=') !== false) return $dn;
                }
                return (string)$vals[0];
            }
        }
    }
    return null;
}

/** People/Users OU 推定（ou=Users を最優先。ゼロ件なら People を試す） */
$detectPeopleOu = static function ($ds, string $baseDn): string {
    $candidates = ["ou=Users,{$baseDn}", "ou=People,{$baseDn}"];

    foreach ($candidates as $dn) {
        // DN が存在するか？
        $res = @ldap_read($ds, $dn, '(objectClass=organizationalUnit)', ['dn'], 0, 1, 3);
        if ($res && ldap_count_entries($ds, $res) > 0) {
            // posixAccount が 1 件以上いるか？（want=0 誤検知ガード）
            $sr = @ldap_search($ds, $dn, '(objectClass=posixAccount)', ['uid'], 0, 1, 3);
            if ($sr && ldap_count_entries($ds, $sr) > 0) {
                return $dn; // ここで決定
            }
        }
    }

    // どちらも見つからない／ゼロ件なら最終手段：baseDn にフォールバック
    return $baseDn;
};


/** エントリ存在チェック */
function ldap_entry_exists($ds, string $dn): bool {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['dn'], 0, 1, 5);
    if (!$sr) return false;
    $e  = @ldap_get_entries($ds, $sr);
    return is_array($e) && (($e['count'] ?? 0) > 0);
}

/** People/Users OU配下の全uid（posixAccount） */
function get_all_people_uids($ds, string $peopleOu, callable $dbg): array {
    $uids = [];
    $res = @ldap_search($ds, $peopleOu, '(objectClass=posixAccount)', ['uid'], 0, 0, 20);
    if (!$res) return [];
    $e = @ldap_get_entries($ds, $res);
    $n = (int)($e['count'] ?? 0);
    for ($i=0; $i<$n; $i++) {
        $uid = (string)($e[$i]['uid'][0] ?? '');
        if ($uid !== '') $uids[] = $uid;
    }
    sort($uids, SORT_STRING);
    $dbg('get_all_people_uids: ' . count($uids) . ' found');
    return $uids;
}

/** クラス別メンバー構築（employeeType の先頭トークン / GroupDef::fromEmployeeType() も使用） */
function get_class_members_from_people($ds, string $peopleOu, callable $dbg): array {
    $want = [
        'adm-cls'=>[], 'dir-cls'=>[], 'mgr-cls'=>[], 'mgs-cls'=>[],
        'stf-cls'=>[], 'ent-cls'=>[], 'tmp-cls'=>[], 'err-cls'=>[],
    ];
    $attrs = ['uid','employeeType','level_id'];
    $res = @ldap_search($ds, $peopleOu, '(objectClass=posixAccount)', $attrs, 0, 0, 30);
    if (!$res) return $want;

    for ($entry = @ldap_first_entry($ds, $res); $entry; $entry = @ldap_next_entry($ds, $entry)) {
        $vals = @ldap_get_attributes($ds, $entry);
        $uid  = (string)($vals['uid'][0] ?? '');
        if ($uid === '') continue;

        $et = (string)($vals['employeeType'][0] ?? '');
        $cls = null;

        if ($et !== '') {
            $tok = preg_split('/\s+/', $et) ?: [];
            $first = strtolower(trim((string)($tok[0] ?? '')));
            if (in_array($first, ['adm-cls','dir-cls','mgr-cls','mgs-cls','stf-cls','ent-cls','tmp-cls','err-cls'], true)) {
                $cls = $first;
            }
        }
        if ($cls === null && method_exists(GroupDef::class, 'fromEmployeeType') && $et !== '') {
            $res2 = GroupDef::fromEmployeeType($et);
            if (is_array($res2) && !empty($res2['name'])) $cls = (string)$res2['name'];
        }
        if ($cls === null) $cls = 'err-cls';

        $want[$cls][] = $uid;
    }

    foreach ($want as $k => $list) {
        $list = array_values(array_unique($list));
        sort($list, SORT_STRING);
        $want[$k] = $list;
    }

    $dbg('get_class_members_from_people done');
    return $want;
}

/** グループの memberUid 一覧を取得 */
function get_group_member_uids($ds, string $groupDn, callable $dbg): array {
    $res = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid'], 0, 1, 10);
    if (!$res) return [];
    $e = @ldap_get_entries($ds, $res);
    if (!is_array($e) || (($e['count'] ?? 0) === 0)) return [];
    $vals = $e[0]['memberuid'] ?? $e[0]['memberUid'] ?? null;
    $out = [];
    if (is_array($vals)) {
        $c = (int)($vals['count'] ?? 0);
        for ($i=0; $i<$c; $i++) {
            $v = (string)$vals[$i];
            if ($v !== '') $out[] = $v;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

