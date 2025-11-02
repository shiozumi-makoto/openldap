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


// ==== Help/Usage ====
// 依存: Tools\Ldap\Env, Tools\Ldap\CliColor（無ければ素のechoにフォールバック）



(function (): void {
    $argvList = array_map('strval', $GLOBALS['argv'] ?? []);
    $wantHelp = false;
    foreach (['--help','-h','help','/?','?'] as $h) {
        if (in_array($h, $argvList, true)) { $wantHelp = true; break; }
    }
    if (!$wantHelp) return;

    $useColor = class_exists(\Tools\Ldap\CliColor::class);
    $B = $useColor ? [\Tools\Ldap\CliColor::class,'bold'] : fn(string $s)=>$s;
    $Y = $useColor ? [\Tools\Ldap\CliColor::class,'yellow'] : fn(string $s)=>$s;
    $G = $useColor ? [\Tools\Ldap\CliColor::class,'green']  : fn(string $s)=>$s;

    // 既定値を実際の計算で表示（Env::get が無ければ素の getenv）
    $envGet = function(string $k, ?string $alt=null, ?string $def=null): ?string {
        if (class_exists(\Tools\Ldap\Env::class)) {
            return \Tools\Ldap\Env::get($k, $alt, $def);
        }
        $v = getenv($k); if ($v!==false && $v!=='') return $v;
        if ($alt) { $v = getenv($alt); if ($v!==false && $v!=='') return $v; }
        return $def;
    };

    $baseDn  = $envGet('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
    $people  = $envGet('PEOPLE_OU', null, "ou=Users,{$baseDn}");
    $groups  = $envGet('GROUPS_OU',  null, "ou=Groups,{$baseDn}");
    $usersDn = $envGet('USERS_GROUP_DN', null, "cn=users,{$groups}");
    $ldapUrl = $envGet('LDAP_URL','LDAP_URI', $envGet('LDAPURI'));

    $text = <<<TXT
{$B('NAME')}
    ldap_memberuid_users_group.php — 全ユーザー(ou=Users)を cn=users の memberUid に所属させる

{$B('SYNOPSIS')}
    php ldap_memberuid_users_group.php [--init] [--confirm] [--list] [--help|-h|?|/?]

{$B('DESCRIPTION')}
    - ユーザー一覧(ou=Users)を取得し、cn=users の memberUid に未所属の uid を追加します。
    - {$Y('DRY-RUN')}: --confirm を付けない限り、変更は一切行いません（計画のみ表示）。
    - {$Y('--init')}: 先に cn=users の memberUid を初期化（全削除）してから再登録します。

{$B('OPTIONS')}

    --group=users or gid 書き換え対象のグループを指定
                         グループ名または gidNumber のどちらでも可
                         例: --group=users, --group=solt-dev, --group=2010
    
    対応表:
        users        → 100
        esmile-dev   → 2001
        nicori-dev   → 2002
        kindaka-dev  → 2003
        boj-dev      → 2005
        e_game-dev   → 2009
        solt-dev     → 2010
        social-dev   → 2012

    --confirm     変更を確定反映。未指定時は DRY-RUN。
    --init        初期化（memberUid 全削除）を有効化。未指定時はスキップ。
    --list        memberUid の登録リストを表示。
    --help, -h, ?, /?  このヘルプを表示。

{$B('ENVIRONMENT')}
    LDAP_URL / LDAP_URI / LDAPURI   接続URI（例: ldapi://%2Fvar%2Frun%2Fldapi / ldaps://FQDN）
    BASE_DN / LDAP_BASE_DN          例: dc=e-smile,dc=ne,dc=jp
    PEOPLE_OU                       例: ou=Users,\$BASE_DN（未指定時は自動）
    GROUPS_OU                       例: ou=Groups,\$BASE_DN（未指定時は自動）
    USERS_GROUP_DN                  例: cn=users,\$GROUPS_OU（未指定時は自動）
    BIND_DN / BIND_PW / LDAP_ADMIN_PW
                                    非ldapi時の simple bind に使用
    INIT_MEMBERUIDS                 "1" で --init と同等（任意）

{$B('CONNECTION POLICY')}
    ldapi://...   SASL/EXTERNAL（パスワード不要）
    ldap://...    StartTLS を必須。失敗時は例外。
    ldaps://...   そのままTLS接続。
    いずれも、必要なら {$G('attrs=memberUid')} への write 権を ACL で付与してください。

{$B('DEFAULTS (resolved now)')}
    LDAP_URL:        {$ldapUrl}
    BASE_DN:         {$baseDn}
    PEOPLE_OU:       {$people}
    GROUPS_OU:       {$groups}
    USERS_GROUP_DN:  {$usersDn}

{$B('EXAMPLES')}
    # 追加のみ（DRY-RUN）
    php ldap_memberuid_users_group.php

    # 追加のみ（確定反映）
    php ldap_memberuid_users_group.php --confirm

    # 初期化してから再登録（DRY-RUN）
    php ldap_memberuid_users_group.php --init

    # 初期化してから再登録（確定反映）
    php ldap_memberuid_users_group.php --init --confirm

TXT;

    echo $text;
    exit(0);
})();

require_once __DIR__ . '/autoload.php';

# Lib
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
# Ldap
use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Ldap\MemberUid;

$cli = new CliUtil([
    'ldap' => [
   	    'uri'     => 'ldaps://ovs-012.e-smile.local',
        'bind_dn' => 'cn=Admin,dc=e-smile,dc=ne,dc=jp',
        'bind_pw' => 'es0356525566',
        'base_dn' => 'ou=Groups,dc=e-smile,dc=ne,dc=jp',
        // 'filter' => '(gidNumber=*)',
        // 'attrs'  => ['cn','gidNumber'],
    ]
]);

$opt   = CliUtil::args($argv);

$confirm = !empty($opt['confirm']);            // --confirm があれば true
$doGroup = $opt['group'] ?? 'users';           // 値付き、未指定なら 'users'
$doList  = !empty($opt['list']);               // --list があれば true
$doInit  = (!empty($opt['init']))              // --init があれば true
        || getenv('INIT_MEMBERUIDS') === '1';  // 環境変数でも有効化

$baseDn       = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$people       = Env::get('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groups       = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$usersGroupDn = Env::get('USERS_GROUP_DN', null, "cn={$doGroup},{$groups}");

try {

    $ds = Connection::connect();
    Connection::bind($ds);

// ★★★ ここがポイント：LDAPから強制的にマップをロード
// CliUtil::initFromLdap($ds, 'ou=Groups,dc=e-smile,dc=ne,dc=jp');
// 
// もしも、


	CliUtil::initFromLdap($ds, $groups );

	$info = $cli->gidByGroupLocal($doGroup);   // ['name'=>'...', 'gid'=>...]

	if ($info === null) {
	    throw new RuntimeException("グループ {$doGroup} が見つかりません。");
	} else {
		['name' => $doGroup, 'gid' => $gid] = $info;
	}

	$usersGroupDn = "cn={$doGroup},{$groups}";

echo " ----------------------------------------------------------- \n";
echo " ldap base => {$groups} \n";
echo " gid       => {$gid} \n";
echo " group     => {$doGroup} \n";
echo " target cn => ${usersGroupDn} \n";
echo " ----------------------------------------------------------- \n";
// exit;

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

//	print_r($users);
//	exit;

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

    echo sprintf("[SUMMARY] 既存登録数 = %d(件) / 新規追加 = %d(件) [ok=%d err=%d]\n", $keep, $planned, $ok, $err);
    Connection::close($ds);
    exit($err ? 2 : 0);

} catch (\Throwable $e) {
    fwrite(STDERR, CliColor::red("[ERROR] ".$e->getMessage()."\n"));
    exit(1);
}
