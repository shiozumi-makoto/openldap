#!/usr/bin/php
<?php
declare(strict_types=1);

require __DIR__ . '/ldap_cli_uri_switch.inc.php';

/**
 * 事業グループ（--group=NAME|GID）と職位クラス（employeeType/level_id）に基づいて
 * posixGroup の memberUid を同期するツール。
 *
 * 1) 事業グループ（例: users, esmile-dev, ...）:
 *    「ユーザーの gidNumber == そのグループの gidNumber」の uid を memberUid として同期
 *    --init 指定時は事業グループ側の memberUid を全削除してから再登録
 * 2) 職位クラス（例: adm-cls, dir-cls, ...）:
 *    employeeType（"adm-cls 1" など）を優先し、無ければ level_id を参照
 *    上記に該当する uid を、該当クラスグループ（gid 3001/3003/...）へ差分同期（--init は対象外）
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/debug_hooks.inc.php';
require_once __DIR__ . '/debug_info.inc.php';
require_once __DIR__ . '/ldap_memberuid_users_group.inc.php'; // println(), fatal() 等

use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Ldap\MemberUid;
use Tools\Ldap\Support\GroupDef;

// ---- ヘルパ ---------------------------------------------------
/** 指定グループDNの memberUid を配列で取得 */
$readMemberUids = static function($ds, string $groupDn): array {
    $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid']);
    if (!$sr) return [];
    $e = ldap_get_entries($ds, $sr);
    if (($e['count'] ?? 0) === 0) return [];
    $out = [];
    if (isset($e[0]['memberuid'])) {
        $cnt = (int)($e[0]['memberuid']['count'] ?? 0);
        for ($i=0; $i<$cnt; $i++) $out[] = $e[0]['memberuid'][$i];
    }
    return array_values(array_unique($out));
};

/** 差分適用（追加＆削除）。$dry=true なら計画表示のみ。*/
$applyMemberUidDiff = static function($ds, string $groupDn, array $want, array $have, bool $dry): array {
    $want = array_values(
        array_unique(
            array_filter(
                array_map('strval', $want),
                static fn($v) => $v !== ''
            )
        )
    );
    $have = array_values(
        array_unique(
            array_filter(
                array_map('strval', $have),
                static fn($v) => $v !== ''
            )
        )
    );

    $add = array_values(array_diff($want, $have));
    $del = array_values(array_diff($have, $want));

    if ($add) println("[INFO] ADD -> " . implode(',', $add));
    if ($del) println("[INFO] DEL -> " . implode(',', $del));

    if ($dry) {
        if ($add) println("[DRY] memberUid ADD on {$groupDn} : [" . implode(',', $add) . "]");
        if ($del) println("[DRY] memberUid DEL on {$groupDn} : [" . implode(',', $del) . "]");
        return ['add'=>count($add), 'del'=>count($del), 'ok'=>0, 'err'=>0];
    }

    $ok = 0; $err = 0;
    if ($add) {
        if (!@ldap_mod_add($ds, $groupDn, ['memberUid' => $add])) {
            $err++;
            fwrite(STDERR, CliColor::red("[ERR ] ADD {$groupDn}: ".ldap_error($ds)."\n"));
        } else {
            $ok += count($add);
        }
    }
    if ($del) {
        if (!@ldap_mod_del($ds, $groupDn, ['memberUid' => $del])) {
            $err++;
            fwrite(STDERR, CliColor::red("[ERR ] DEL {$groupDn}: ".ldap_error($ds)."\n"));
        } else {
            $ok += count($del);
        }
    }
    return ['add'=>count($add), 'del'=>count($del), 'ok'=>$ok, 'err'=>$err];
};
// ---------------------------------------------------------------

// ★argv option 処理
$opt      = CliUtil::args($argv);
$doInit   = !empty($opt['init']);       // --init アカウント初期化（事業グループのみ）
$doList   = !empty($opt['list']);       // --list リスト表示
$confirm  = !empty($opt['confirm']);    // --confirm 実行
$noCls    = !empty($opt['no-cls']);     // --no-cls 職位クラス側同期しない

// ★--group 解決（ローカル名 or gid）
['flag' => $flag, 'gid'  => $gid, 'name' => $doGroup ]
    = CliUtil::gidByGroupLocalNameGid($opt['group'] ?? 'users');

// ★環境変数「取得」
$baseDn        = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$peopleOu      = Env::get('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groupOu       = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$bizGroupDn    = Env::get('USERS_GROUP_DN', null, "cn={$doGroup},{$groupOu}");

// ☆インクルードファイル表示 --inc set!
[$confirm, $wantInc] = DebugHooks::init($opt);

$ldapUri = getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: '(not set)';

// ☆ --confirm Debug info section
if (!$confirm) {
    DebugInfo::print([
        'gid'          => $gid,
        'doGroup'      => $doGroup,
        'bizGroupDn'   => $bizGroupDn,
        'groups [ou]'  => $groupOu,
        'confirm'      => $confirm,
        'wantInc'      => $wantInc,
        'ldap_uri'     => $ldapUri,
    ]);
    echo CliColor::boldRed("[INFO] DRY-RUN: use --confirm to write changes\n");
}

// ==== Main ====================================================
try {
    // 1) 環境から接続情報を初期化 → 接続 → bind
    Connection::init();
    $ds = Connection::connect();
    Connection::bind($ds);

    // -----------------------------------------------------------
    // 2) 事業グループ side
    // -----------------------------------------------------------
    // --init 指定時は事業グループの memberUid 全削除（confirm 無しなら planned）
    $resInit = MemberUid::deleteGroupMemberUids($ds, $bizGroupDn, $confirm, $doInit);
    if (!empty($resInit['skipped'])) {
        echo "[INIT] skipped (use --init to enable)\n";
    } else {
        echo sprintf("[INIT] delete targets=%d%s\n",
            $resInit['count'],
            $confirm ? '' : ' (planned)'
        );
    }

    // 対象ユーザーを取得（ou=Users）
    $users = MemberUid::fetchUsers($ds, $peopleOu);

    // --group=users 以外は gidNumber でフィルタ
    if ($doGroup !== 'users') {
        $users = array_values(array_filter($users, static fn($u) => (int)($u['gidNumber'] ?? -1) === (int)$gid));
    }

    $memberSet = null; // MemberUid::add 内キャッシュ
    $keep = $planned = $ok = $err = 0;

    foreach ($users as $u) {
        $uid = (string)($u['uid'] ?? '');
        if ($uid === '') { $keep++; continue; }
        try {
            $did = MemberUid::add($ds, $bizGroupDn, $uid, $confirm, $memberSet);
            if ($did) { $planned++; if ($confirm) $ok++; } else { $keep++; }
        } catch (\Throwable $e) {
            $err++;
            fwrite(STDERR, CliColor::red("[ERR ] {$uid}: ".$e->getMessage()."\n"));
        }
    }

    if ($doList) {
        $memberGroup = MemberUid::memberGroupGet($memberSet);
        MemberUid::printMemberGroup($memberGroup);
    }

    echo CliColor::boldGreen(sprintf(
        "[SUMMARY:BIZ] 既存=%d / 追加計画=%d [ok=%d err=%d]\n",
        $keep, $planned, $ok, $err
    ));

// -----------------------------------------------------------
// 3) 職位クラス side（employeeType のみ利用） ※ --no-cls で無効化
// -----------------------------------------------------------
if (!$noCls) {
    // すべての Users から employeeType を直接取得
    $clsWants = [];  // gid => [uid...]
    foreach (\Tools\Ldap\Support\GroupDef::DEF as $def) { $clsWants[$def['gid']] = []; }

    $filterAll = '(objectClass=posixAccount)';
    $attrsAll  = ['uid', 'employeeType']; // employeeType だけで十分
    $srAll     = @ldap_search($ds, $peopleOu, $filterAll, $attrsAll);
    if (!$srAll) {
        throw new \RuntimeException("ldap_search failed on $peopleOu: " . ldap_error($ds));
    }
    $entriesAll = ldap_get_entries($ds, $srAll);

    for ($i = 0; $i < ($entriesAll['count'] ?? 0); $i++) {
        $e   = $entriesAll[$i];
        $uid = $e['uid'][0] ?? '';
        if ($uid === '') continue;

        // 小文字キー employeetype でも返ってくることがあるので両対応
        $emp = $e['employeetype'][0] ?? ($e['employeeType'][0] ?? '');
        if ($emp === '') continue;

        $cls = \Tools\Ldap\Support\GroupDef::fromEmployeeType($emp);
        if (!$cls) continue;

        $clsWants[$cls['gid']][] = $uid;
    }

    // 各クラスグループへ差分適用（want=0 の場合は安全のため削除しない）
    foreach (\Tools\Ldap\Support\GroupDef::DEF as $def) {
        $gName = $def['name'];             // 例: adm-cls
        $gDn   = "cn={$gName},{$groupOu}"; // ou=Groups 配下固定

        // 正規化
        $want = array_values(array_unique($clsWants[$def['gid']] ?? []));
        $have = $readMemberUids($ds, $gDn);

        if ($doList) {
            println(sprintf("[LIST] CLS %-8s want(%d): %s", $gName, count($want), implode(',', $want)));
            println(sprintf("[LIST] CLS %-8s have(%d): %s", $gName, count($have), implode(',', $have)));
        }

        // セーフティ：want=0 の時は削除しない（データ取りこぼし防止）
        if (count($want) === 0) {
            println("[SAFE] skip delete on {$gName} because want=0");
            // 追加も削除も行わない
            continue;
        }

        $r = $applyMemberUidDiff($ds, $gDn, $want, $have, !$confirm);
        echo \Tools\Lib\CliColor::green(sprintf(
            "[SUMMARY:CLS %-8s] add=%d del=%d %s\n",
            $gName, $r['add'], $r['del'], $confirm ? "[applied]" : "[planned]"
        ));
    }
}

    // 4) 後片付け
    Connection::close($ds);
    exit($err ? 2 : 0);

} catch (\Throwable $e) {
    fwrite(STDERR, CliColor::red("[ERROR] ".$e->getMessage()."\n"));
    exit(1);
}


