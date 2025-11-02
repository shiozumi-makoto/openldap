#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * 全ユーザー(ou=Users)を cn=users へ memberUid で所属させる。
 *
 * 接続仕様:
 *  - 環境変数 LDAP_URL / LDAP_URI / LDAPURI を優先
 *    * ldapi://%2Fvar%2Frun%2Fldapi → SASL/EXTERNAL で接続（パスワード不要）
 *    * ldap://host → StartTLS を必ず実行してから bind
 *    * ldaps://host → そのまま bind
 *  - BIND_DN / BIND_PW を使用（ldapi/EXTERNAL時は未使用）
 *
 * 使い方:
 *   php ldap_memberuid_users_group.php [--confirm]
 *
 * 必要な環境変数（なければ既定値）:
 *   LDAP_URL / LDAP_URI / LDAPURI     接続URI（例: ldapi://%2Fvar%2Frun%2Fldapi / ldaps://FQDN）
 *   BASE_DN / LDAP_BASE_DN            例: dc=e-smile,dc=ne,dc=jp
 *   PEOPLE_OU                         例: ou=Users,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   GROUPS_OU                         例: ou=Groups,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   USERS_GROUP_DN                    例: cn=users,ou=Groups,${BASE_DN}（未指定なら上記で自動）
 *   BIND_DN / BIND_PW                 管理者バインドに利用（ldapi/EXTERNAL時は不要）
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/debug_hooks.inc.php';
require_once __DIR__ . '/debug_info.inc.php';

// ★★Lib Ldap 共通ライブラリ
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Ldap\MemberUid;

// ★★argv opton 処理
$opt     = CliUtil::args($argv);

$confirm = !empty($opt['confirm']);            // --confirm があれば true
$doGroup = $opt['group'] ?? 'users';           // 値付き、未指定なら 'users'
$doList  = !empty($opt['list']);               // --list があれば true
$doInit  = (!empty($opt['init']))              // --init があれば true
        || getenv('INIT_MEMBERUIDS') === '1';  // 環境変数でも有効化

// $wantInc = !empty($opt['inc']);             // --inc があれば true
// confirm / inc 管理を外部化
[$confirm, $wantInc] = DebugHooks::init($opt);


/*
// ★★--inc オプション時のみ、終了時に一覧を出す
if ($wantInc) {
	register_shutdown_function(function() {
	    echo PHP_EOL . "--- Included files -------------------------" . PHP_EOL;
	    foreach (get_included_files() as $f) {
	        echo "  $f" . PHP_EOL;
	    }
		    echo "--------------------------------------------" . PHP_EOL;
	});
}
*/

// ★★環境変数から取得
$baseDn       = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$people       = Env::get('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groups       = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$usersGroupDn = Env::get('USERS_GROUP_DN', null, "cn={$doGroup},{$groups}");

// ==== Help/Usage ====
// 依存: Tools\Ldap\Env, Tools\Ldap\CliColor（無ければ素のechoにフォールバック）
// ★★-help オプション時
require_once str_replace('.php', '.inc', __FILE__);



// ==== Main/Excee ====

try {
    $ds = Connection::connect();
    Connection::bind($ds);

	$info = CliUtil::gidByGroupLocalNameGid($doGroup);

	if ($info === null || $info['flag'] === false ) {
	    throw new RuntimeException("グループ {$doGroup} が見つかりません。");
	} else {
		['flag' => $flag, 'gid' => $gid, 'name' => $doGroup] = $info;
	}

	$usersGroupDn = "cn={$doGroup},{$groups}";

	// ★★ Debug info section
	if (!$confirm) {
	    DebugInfo::print([
	        'confirm'      => $confirm,
	        'wantInc'      => $wantInc,
            'gid'          => $gid,
	        'doGroup'      => $doGroup,
	        'usersGroupDn' => $usersGroupDn,
	        'groups [ou]'  => $groups,
	    ]);
	}

    if (!$confirm) {
        echo CliColor::boldRed("[INFO] DRY-RUN: use --confirm to write changes\n");
    }

    // 1) 初期化（--init のときだけ実行）
    $res = MemberUid::deleteGroupMemberUids($ds, $usersGroupDn, $confirm, $doInit);
    if (!empty($res['skipped'])) {
        echo "[INIT] skipped (use --init to enable)\n";
    } else {
        echo sprintf("[INIT] delete targets=%d%s\n",
            $res['count'],
            $confirm ? '' : ' (planned)'
        );
    }

    // 2) 再登録
    $users = MemberUid::fetchUsers($ds, $people);

	if($doGroup !== 'users') {
		$filtered = array_filter($users, fn($u) => (int)$u['gidNumber'] === $gid);
		// インデックスを詰め直したい場合（0,1,2...）
		$users    = array_values($filtered);
	}

    $memberSet = null; // add() 内で初期化されるキャッシュ
    $planned=0; $ok=0; $keep=0; $err=0;

    foreach ($users as $u) {
        $uid = $u['uid'] ?? '';
        if ($uid === '') { $keep++; continue; }

        try {
            $did = MemberUid::add($ds, $usersGroupDn, $uid, $confirm, $memberSet);
            if ($did) { $planned++; if ($confirm) $ok++; } else { $keep++; }
        } catch (\Throwable $e) {
            $err++;
            fwrite(STDERR, CliColor::red("[ERR ] $uid: ".$e->getMessage()."\n"));
        }
    }

    if ($doList) {
		$memberGroup = MemberUid::memberGroupGet($memberSet);
		MemberUid::printMemberGroup($memberGroup);
    }

	$summary = sprintf("[SUMMARY] 既存登録数 = %d(件) / 新規追加 = %d(件) [ok=%d err=%d]\n", $keep, $planned, $ok, $err);
	echo CliColor::boldGreen($summary);

    Connection::close($ds);
    exit($err ? 2 : 0);

} catch (\Throwable $e) {
    fwrite(STDERR, CliColor::red("[ERROR] ".$e->getMessage()."\n"));
    exit(1);
}
