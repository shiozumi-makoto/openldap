#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_level_groups_sync.php
 * refactored: 2025-10-22
 *
 * 目的:
 *  - 指定されたグループ名（cn）に対して、ou=Groups 配下へ posixGroup を生成/整合する
 *  - gidNumber は GroupDef の定義（BIZ_MAP / DEF / 'users'）を優先して採用
 *
 * 主なオプション:
 *  --help                 ヘルプ表示
 *  --init-group           グループを作成（存在すればスキップ）
 *  --group=NAME[,..]      対象グループを指定（例: users,adm-cls,esmile-dev）
 *  --confirm              変更適用（未指定なら DRY-RUN）
 *  --ldapi                ldapi で接続（既定URIが未指定の時のみ）
 *  --ldap-uri=URI         明示的に LDAP URI を指定（互換: --uri と同義）
 *  --uri=URI              同上（互換）
 *  --base-dn=DN           既定は BIND_DN から推定
 *  --groups-ou=DN         既定: ou=Groups,<BASE_DN>
 *
 * 依存:
 *  - ext-ldap
 *  - （任意）Tools\Ldap\Support\GroupDef
 *  - （任意）Tools\Lib\CliColor
 */

@require_once __DIR__ . '/autoload.php';

use Tools\Ldap\Support\GroupDef;

$isColor = class_exists(\Tools\Lib\CliColor::class);
$C = [
    'bold'   => $isColor ? [\Tools\Lib\CliColor::class,'bold']      : fn($s)=>$s,
    'green'  => $isColor ? [\Tools\Lib\CliColor::class,'green']     : fn($s)=>$s,
    'yellow' => $isColor ? [\Tools\Lib\CliColor::class,'yellow']    : fn($s)=>$s,
    'red'    => $isColor ? [\Tools\Lib\CliColor::class,'red']       : fn($s)=>$s,
    'bcyan'  => $isColor ? [\Tools\Lib\CliColor::class,'boldCyan']  : fn($s)=>$s,
    'bgreen' => $isColor ? [\Tools\Lib\CliColor::class,'boldGreen'] : fn($s)=>$s,
];

function info($m){ global $C; echo ($C['green'])("[INFO] ").$m."\n"; }
function warn($m){ global $C; echo ($C['yellow'])("[WARN] ").$m."\n"; }
function err ($m){ global $C; fwrite(STDERR, ($C['red'])("[ERROR] ").$m."\n"); }

// --- polyfill ---
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

//--------------------------------------------------------------
// CLI parse（CliUtil::parse() があれば使用、なければ getopt）
//--------------------------------------------------------------
$options = null;
if (class_exists(\Tools\Lib\CliUtil::class) && method_exists(\Tools\Lib\CliUtil::class,'parse')) {
    /** @var array<string,mixed> */
    $options = \Tools\Lib\CliUtil::parse([
        'help'       => [false, 'bool'],
        'init-group' => [false, 'bool'],
        'group'      => [null,  'string'],
        'confirm'    => [false, 'bool'],
        'ldapi'      => [false, 'bool'],
        'ldap-uri'   => [null,  'string'],
        'uri'        => [null,  'string'],
        'base-dn'    => [null,  'string'],
        'groups-ou'  => [null,  'string'],
        'description'=> [null,  'string'],
    ]);
} else {
    $options = getopt('', [
        'help',
        'init-group',
        'group::',
        'confirm',
        'ldapi',
        'ldap-uri::',
        'uri::',
        'base-dn::',
        'groups-ou::',
        'description::',
    ]);
}

if (isset($options['help'])) {
    $bin = basename(__FILE__);
    echo <<<HELP
{$bin} - create/sync posixGroup entries under ou=Groups

Usage:
  php {$bin} --init-group --group=users,adm-cls,esmile-dev [--confirm]
             [--ldapi | --ldap-uri=URI | --uri=URI]
             [--base-dn=DN] [--groups-ou=DN] [--help]

Options:
  --help            Show this help
  --init-group      Create group(s) if missing (posixGroup + gidNumber)
  --group=LIST      Comma separated cn list (e.g. users,adm-cls,esmile-dev)
  --confirm         Apply changes (otherwise DRY-RUN)
  --ldapi           Use ldapi:/// by default if URI is not specified
  --ldap-uri=URI    Explicit LDAP URI (alias: --uri)
  --uri=URI         Alias of --ldap-uri
  --base-dn=DN      Base DN (default: inferred from BIND_DN)
  --groups-ou=DN    Groups OU DN (default: ou=Groups,<BASE_DN>)
  --description     Description (update!)

HELP;
    exit(0);
}

//--------------------------------------------------------------
// Resolve parameters
//--------------------------------------------------------------
$CONFIRM = isset($options['confirm']);
$DO_INIT = isset($options['init-group']);
$DO_UPDS = isset($options['description']);	// $description = true;

$URI_OPT = $options['ldap-uri'] ?? ($options['uri'] ?? null);
$LDAP_URL = getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: ($URI_OPT ?: null);
if (!$LDAP_URL && isset($options['ldapi'])) {
    $LDAP_URL = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
}
if (!$LDAP_URL) {
    // 既定は ldapi を使う（安全運用）
    $LDAP_URL = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
}

$BIND_DN = getenv('BIND_DN') ?: 'cn=Admin,dc=e-smile,dc=ne,dc=jp';
$BIND_PW = getenv('BIND_PW') ?: '';

$BASE_DN = $options['base-dn'] ?? (getenv('BASE_DN') ?: null);
if (!$BASE_DN) {
    $BASE_DN = preg_replace('/^[^,]+,/', '', $BIND_DN);
}

$GROUPS_OU = $options['groups-ou'] ?? (getenv('GROUPS_OU') ?: "ou=Groups,{$BASE_DN}");

// groups list
$TARGET_GROUPS = [];
if (!empty($options['group'])) {
    $TARGET_GROUPS = array_values(array_filter(array_map('trim', explode(',', (string)$options['group'])), fn($s)=>$s!==''));
}
if (!$TARGET_GROUPS) {
    warn("No --group specified. Nothing to do.");
    exit(0);
}

//--------------------------------------------------------------
// Print header
//--------------------------------------------------------------
echo "\n";
echo ($C['bcyan'])("=== level groups sync (posixGroup ensure) ===\n");
printf("URI       : %s\n", $LDAP_URL);
printf("BASE_DN   : %s\n", $BASE_DN);
printf("GROUPS_OU : %s\n", $GROUPS_OU);
printf("MODE      : %s\n", $CONFIRM ? 'APPLY' : 'DRY-RUN');
printf("TARGETS   : %s\n", implode(',', $TARGET_GROUPS));
printf("DISCRIPT  : %s\n", ((bool)$DO_UPDS) ? "true" : "false");
echo "----------------------------------------------\n";

//--------------------------------------------------------------
// LDAP connect/bind
//--------------------------------------------------------------
$link = @ldap_connect($LDAP_URL);
if (!$link) { err("ldap_connect failed: {$LDAP_URL}"); exit(1); }
@ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
@ldap_set_option($link, LDAP_OPT_REFERRALS, 0);

$wantExternal = str_starts_with($LDAP_URL, 'ldapi://');
$bindOk = false;
if ($wantExternal && function_exists('ldap_sasl_bind')) {
    putenv('LDAPTLS_REQCERT=never');
    $bindOk = @ldap_sasl_bind($link, null, null, 'EXTERNAL');
} else {
    $bindOk = @ldap_bind($link, $BIND_DN, $BIND_PW);
}
if (!$bindOk) { err("ldap_bind failed: ".(function_exists('ldap_error')?ldap_error($link):'unknown')); exit(1); }

//--------------------------------------------------------------
// GroupDef::all();
//--------------------------------------------------------------
// 対象セット
$GROUP_DEF = GroupDef::all_id();
//print_r($GROUP_DEF);
//exit;

//--------------------------------------------------------------
// helpers
//--------------------------------------------------------------
function group_dn(string $cn, string $groupsOu): string {
    return "cn={$cn},{$groupsOu}";
}
function ldap_entry_exists($link, string $dn): bool {
    $sr = @ldap_read($link, $dn, '(objectClass=*)', ['dn']);
    if ($sr === false) return false;
    $e = @ldap_get_entries($link,$sr);
    return is_array($e) && (($e['count'] ?? 0) > 0);
}
function read_gid($link, string $dn): ?int {
    $sr = @ldap_read($link, $dn, '(objectClass=posixGroup)', ['gidNumber']);
    if (!$sr) return null;
    $e = @ldap_get_entries($link,$sr);
    if (!is_array($e) || ($e['count'] ?? 0) === 0) return null;
    if (!isset($e[0]['gidnumber'][0])) return null;
    return (int)$e[0]['gidnumber'][0];
}

/**
 * GroupDef から gidNumber を推定:
 *  - users => 100（慣例）
 *  - BIZ_MAP[name] があればそれ
 *  - DEF 配列の 'name' が一致すれば 'gid'
 */
function infer_gid_from_defs(string $cn): ?int {
    // users
    if ($cn === 'users') return 100;

    // BIZ_MAP
    if (class_exists(GroupDef::class) && defined(GroupDef::class.'::BIZ_MAP')) {
        /** @var array<string,int> $biz */
        $biz = constant(GroupDef::class.'::BIZ_MAP');
        if (is_array($biz) && isset($biz[$cn])) return (int)$biz[$cn];
    }
    // DEF
    if (class_exists(GroupDef::class) && defined(GroupDef::class.'::DEF')) {
        /** @var array<int,array{name:string,gid:int}> $def */
        $def = constant(GroupDef::class.'::DEF');
        if (is_array($def)) {
            foreach ($def as $row) {
                if (!isset($row['name'],$row['gid'])) continue;
                if ((string)$row['name'] === $cn) return (int)$row['gid'];
            }
        }
    }
    return null;
}

/** posixGroup を生成（存在しなければ add）し、gidNumber を整合させる */


function ensure_posix_group($link, string $dn, string $cn, ?int $gid, bool $confirm, $group_def, bool $desc_update): array {

    $created = false; $fixedGid = false;
    $description = $group_def[$gid]['description'] ?? 'Domain Unix group';
//	echo $description;
//	exit;
/*
$group_def =

    [3021] => Array
        (
            [type] => cls
            [name] => tmp-cls
            [gid] => 3021
            [min] => 21
            [max] => 98
            [display] => Temporary Class (21?98) / 派遣・退職者
            [description] => level (21)
        )

    [3099] => Array
        (
            [type] => cls
            [name] => err-cls
            [gid] => 3099
            [min] => 99
            [max] => 9999
            [display] => Error Class (99) / 例外処理・未定義ID用
            [description] => level (99)
        )
*/

    if (!ldap_entry_exists($link, $dn)) {
        if ($gid === null) {
            return [false, false, "gidNumberを決められず作成不可（GroupDef定義の不足 or 明示指定必要）"];
        }
        $entry = [
            'objectClass' => ['top','posixGroup'],
            'cn'          => $cn,
            'gidNumber'   => (string)$gid,
            'description' => $description,
        ];
        if ($confirm) {
            $ok = @ldap_add($link, $dn, $entry);
            if (!$ok) return [false, false, "ldap_add失敗: ".ldap_error($link), $description];
        }
        $created = true;
        return [$created, $fixedGid, null, $description];
    }

    // 既存: gidNumber の整合をチェック
    $current = read_gid($link, $dn);

    if (($gid !== null && $current !== null && $gid !== $current) || ($desc_update === true)) {
        // 安全のため、既存 gid と異なる場合は差し替えを実行（要件に応じて方針変更可）
        if ($confirm) {
            $ok = @ldap_modify($link, $dn, ['gidNumber'=>(string)$gid, 'description' => $description ]);
            if (!$ok) return [$created, $fixedGid, "gidNumber更新失敗: ".ldap_error($link), $description];
        }
        $fixedGid = true;
/*
	echo " **************************** up date description! $gid \n";
	print_r( ['gidNumber'=>(string)$gid, 'description' => $group_def[$gid]['description'] ?? 'Domain Unix group'] );
	var_dump($description);
	var_dump($current);
	var_dump($gid);
	echo " **************************** up date description! \n";
	exit;
*/

	    } else {

		}
    return [$created, $fixedGid, null, $description];
}

//--------------------------------------------------------------
// MAIN
//--------------------------------------------------------------
$totalCreated = 0; $totalFixed = 0;

foreach ($TARGET_GROUPS as $cn) {
    $dn  = group_dn($cn, $GROUPS_OU);
    $gid = infer_gid_from_defs($cn);

    if ($gid === null && ldap_entry_exists($link,$dn)) {
        // 既存なら現行 gid を採用して続行（新規は不可）
        $gid = read_gid($link, $dn);
    }

    printf("[TASK] cn=%s gid=%s dn=%s\n", $cn, $gid===null?'(unknown)':(string)$gid, $dn);

    if (!$DO_INIT) {
        info("DRY: --init-group 未指定のため何もしません（確認だけ）");
        if (!ldap_entry_exists($link,$dn)) {
            warn("MISSING: {$dn}");
        } else {
            $cg = read_gid($link,$dn);
            info("EXISTS: {$dn} (gidNumber=".($cg===null?'null':$cg).")");
        }
        continue;
    }

/*
	echo " **************************** \n";
	var_dump($DO_INIT);
	echo " **************************** \n";
	exit;
*/

    [$created,$fixed,$err,$description] = ensure_posix_group($link, $dn, $cn, $gid, $CONFIRM, $GROUP_DEF, $DO_UPDS);
    if ($err !== null) {
        warn("{$cn}: {$err}");
        continue;
    }
    if ($created) { $totalCreated++; info(($CONFIRM?'ADD ':'DRY ADD ')."posixGroup {$dn} (gid={$gid})"); }
    if ($fixed)   { $totalFixed++;   info(($CONFIRM?'MOD ':'DRY MOD ')."gidNumber → {$gid}"); }
    if ($fixed)   { $totalFixed++;   info(($CONFIRM?'MOD ':'DRY MOD ')."description → {$description}"); }
    if (!$created && !$fixed) { info("OK: {$cn}（変更なし）"); }
}

echo "\n";
echo ($C['bgreen'])("[DONE] level groups sync complete  created={$totalCreated} fixed_gid={$totalFixed}  ").($CONFIRM?'(APPLIED)':'(DRY-RUN)')."\n";

// cleanup
if ($link) { @ldap_unbind($link); }

