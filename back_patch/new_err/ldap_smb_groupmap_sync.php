#!/usr/bin/env php
<?php
declare(strict_types=1);
declare(strict_types=1);

/**
 * ldap_smb_groupmap_sync.php
 *  - ou=Groups の posixGroup に対し、Samba の sambaGroupMapping を一括で付与/整合
 *  - 事業系(BIZ_MAP)／職位クラス系(DEF) の両方に対応（RIDは固定ルール）
 *  - Tools\Ldap\Support\LdapUtil（$ds=php-ldapリソース）APIを利用:
 *      inferDomainSid($ds, $baseDn): ?string
 *      readEntries($ds, $base, $filter, array $attrs=['*']): array
 *      ensureGroupMapping($ds, string $dn, string $sid, string $display, string $type, bool $confirm, callable $info, callable $warn): void
 *
 * 使い方:
 *   php ldap_smb_groupmap_sync.php --biz   --verbose
 *   php ldap_smb_groupmap_sync.php --level --confirm
 *   php ldap_smb_groupmap_sync.php --all   --confirm --group=stf-cls
 *
 * 接続指定（必要に応じて）:
 *   --uri='ldaps://host' --bind-dn='cn=admin,dc=...' --bind-pw='***'
 *   既定は ldapi + SASL/EXTERNAL（--bind-dn未指定時、Connection::bind に委譲）
 */

ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Tokyo');

// ===== 共通ライブラリ・オートローダ =====
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/inc/ldap_cli_uri_switch.inc.php';
require_once __DIR__ . '/inc/cli_help_connect.inc.php';

use Tools\Ldap\Support\GroupDef;
use Tools\Ldap\Support\LdapUtil;
use Tools\Ldap\Connection;



// ---------------- CLI ----------------
$args = getopt('', [
    'biz',
    'level',
    'all',
    'group:',
    'confirm',
    'verbose',
    'uri:',
    'base-dn:',
    'bind-dn:',
    'bind-pw:',
    'help',
 ]);


// --- help option ---
if (isset($args['help']) || in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
    $prog = basename($_SERVER['argv'][0] ?? 'ldap_smb_groupmap_sync.php');
    echo "使い方:\n";
    echo "  php {$prog} [--all|--group=<name>] [--confirm] [--verbose]\n";
    echo "  php {$prog} --help\n\n";
    echo "オプション:\n";
    echo "  --help       このヘルプを表示\n";
    echo "  --ldapi      ldapi:// ローカルソケット接続（推奨）\n";
    echo "  --ldaps      ldaps:// TLS接続（BIND_DN / BIND_PW 必要）\n";
    echo "  --uri=URI    明示的なLDAP URIを指定（ldaps://host:636 等）\n";
    echo "  --confirm    実際に変更を反映\n";
    echo "  --verbose    詳細出力\n";
    if (function_exists('cli_connect_samples')) {
        echo cli_connect_samples($prog);
    }
    exit(0);
}
$arg = static function (string $k, $default=null) use ($args) {
    return array_key_exists($k, $args) ? $args[$k] : $default;
};

$arg = static function (string $k, $default=null) use ($args) {
    return array_key_exists($k, $args) ? $args[$k] : $default;
};

// ← これを追加：フラグは「存在で判定」
$has = static function (string $k) use ($args): bool {
    return array_key_exists($k, $args);
};

$MODE_BIZ   = $has('biz');
$MODE_LEVEL = $has('level');
$MODE_ALL   = $has('all') || (!$MODE_BIZ && !$MODE_LEVEL);

$TARGET_ONE = $arg('group', null);
$CONFIRM    = $has('confirm');
$VERBOSE    = $has('verbose');

$URI     = $arg('uri',     getenv('LDAP_URI') ?: 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi');
$BASE_DN = $arg('base-dn', getenv('LDAP_BASE_DN') ?: getenv('BASE_DN') ?: 'dc=e-smile,dc=ne,dc=jp');
$BIND_DN = $arg('bind-dn', getenv('BIND_DN') ?: null);
$BIND_PW = $arg('bind-pw', getenv('BIND_PW') ?: null);

$GROUPS_DN = 'ou=Groups,' . $BASE_DN;

// ---- RID 決定ロジック（確定版：二車線ルール + users 特番） ----
$ridFor = function(string $cn, int $gid): ?int {
    // users は固定
    if ($cn === 'users') return 1008;

    // 事業系（*-dev）は gid - 1000
    if (preg_match('/-dev$/', $cn)) return $gid - 1000;

    // 職位クラス（固定テーブル）
    static $clsRid = [
        'adm-cls' => 1009,
        'dir-cls' => 1010,
        'mgr-cls' => 1011,
        'mgs-cls' => 1012,
        'stf-cls' => 1013,
        'ent-cls' => 1014,
        'tmp-cls' => 1015,
        'err-cls' => 1016,
    ];
    return $clsRid[$cn] ?? null;
};

// ログ
$log = function (string $lvl, string $msg) use ($VERBOSE) {
    static $colors = [
        'INFO'=>"\033[36m",'OK'=>"\033[32m",'ADD'=>"\033[92m",
        'UPD'=>"\033[33m",'SKIP'=>"\033[90m",'ERR'=>"\033[31m",'DBG'=>"\033[90m",
    ];
    if ($lvl==='DBG' && !$VERBOSE) return;
    $c=$colors[$lvl]??""; $r=$c?"\033[0m":"";
    echo "{$c}[{$lvl}] {$msg}{$r}\n";
};

// ---------------- 実行開始 ----------------
$log('INFO', "開始: ".date('Y-m-d H:i:s'));
$log('INFO', "モード: ".($CONFIRM?'APPLY':'DRY-RUN')." | 対象: ".($MODE_BIZ?'BIZ':($MODE_LEVEL?'LEVEL':'ALL')));
$log('DBG',  "URI={$URI} BASE_DN={$BASE_DN} GROUPS_DN={$GROUPS_DN}");

// ---------------- LDAP接続（Connection に委譲） ----------------
try {
    $ds = Connection::connect($URI);
    // bindDN/Pass が null の場合は、Connection 側で ldapi/SASL-EXTERNAL or anonymous を選択
    Connection::bind($ds, $BIND_DN, $BIND_PW, $URI);
} catch (\Throwable $e) {
    $log('ERR', 'LDAP接続/Bindに失敗: '.$e->getMessage());
    exit(2);
}

// ---------------- 定義セット ----------------
$defsBiz = [];
$defsLevel = [];
if (is_array(GroupDef::BIZ_MAP ?? null)) {
    foreach (GroupDef::BIZ_MAP as $name => $gid) {
        $defsBiz[] = ['name'=>(string)$name, 'gid'=>(int)$gid, 'display'=>(string)$name];
    }
}
if (is_array(GroupDef::DEF ?? null)) {
    foreach (GroupDef::DEF as $row) {
        if (!isset($row['name'],$row['gid'])) continue;
        $defsLevel[] = [
            'name'=>(string)$row['name'],
            'gid'=>(int)$row['gid'],
            'display'=>(string)($row['display'] ?? $row['name']),
        ];
    }
}
$targets = [];
if ($MODE_BIZ)   $targets = array_merge($targets, $defsBiz);
if ($MODE_LEVEL) $targets = array_merge($targets, $defsLevel);
if ($MODE_ALL)   $targets = array_merge($defsBiz, $defsLevel);

if ($TARGET_ONE) {
    $targets = array_values(array_filter($targets, fn($r) => $r['name'] === $TARGET_ONE));
    if (!$targets) { $log('ERR', "定義に見つからないグループ: {$TARGET_ONE}"); exit(3); }
}
if (!$targets) { $log('SKIP', "対象が空です。終了します。"); exit(0); }

// ---------------- Domain SID / ルール表示 ----------------
$domSid = LdapUtil::inferDomainSid($ds, $BASE_DN);
if (!$domSid) {
    $log('ERR', "DomainSID を取得できませんでした。sambaDomain の有無や 'net getlocalsid' を確認してください。");
    exit(4);
}

$log('INFO', "RID ルール: users=1008, *-dev=(gid-1000), cls={adm:1009,dir:1010,mgr:1011,mgs:1012,stf:1013,ent:1014,tmp:1015,err:1016}");
$log('DBG',  "DomainSID: {$domSid}");

// ---------------- メイン処理 ----------------
$stats = ['total'=>0,'ok'=>0,'added'=>0,'updated'=>0,'skip_no_posix'=>0,'skip_ng'=>0];

foreach ($targets as $t) {
    $stats['total']++;
    $cn       = $t['name'];
    $wantGid  = (int)$t['gid'];
    $wantDisp = (string)$t['display'];
    $dn = "cn={$cn},{$GROUPS_DN}";

    // 1) 現在状態の取得
    $es = LdapUtil::readEntries($ds, $GROUPS_DN, "(cn={$cn})",
        ['dn','objectClass','gidNumber','sambaSID','displayName','sambaGroupType']);
    if (!$es) {
        $log('SKIP', "存在しないためSKIP: {$dn}");
        $stats['skip_no_posix']++; continue;
    }
    $e = $es[0];

    // objectClass チェック
    $classes = [];
    if (isset($e['objectclass']) && is_array($e['objectclass'])) {
        $cnt = $e['objectclass']['count'] ?? 0;
        for ($i=0; $i<$cnt; $i++) $classes[] = strtolower((string)$e['objectclass'][$i]);
    }
    $hasPosix = in_array('posixgroup', $classes, true);
    $hasMap   = in_array('sambagroupmapping', $classes, true);

    $curGid   = isset($e['gidnumber'][0]) ? (int)$e['gidnumber'][0] : null;
    $curDisp  = $e['displayname'][0] ?? null;
    $curType  = isset($e['sambagrouptype'][0]) ? (int)$e['sambagrouptype'][0] : null;
    $curSid   = $e['sambasid'][0] ?? null;

    if (!$hasPosix) {
        $log('SKIP', "posixGroup でないためSKIP: {$dn}");
        $stats['skip_no_posix']++; continue;
    }
    if ($curGid !== $wantGid) {
        $log('SKIP', "gid 不一致: cn={$cn} want={$wantGid} actual=".($curGid===null?'NULL':$curGid));
        $stats['skip_ng']++; continue;
    }

    // 2) RID/SID 算出（固定ルール）
    $rid = $ridFor($cn, $wantGid);
    if ($rid === null) {
        $log('SKIP', "RID未定義: cn={$cn} gid={$wantGid}");
        $stats['skip_ng']++; continue;
    }
    $wantSid = "{$domSid}-{$rid}";
    $wantType = 2; // DOMAIN_GROUP
    $need = [];

    if ($hasMap) {
        // 既存との差分チェック
        if ($curSid !== $wantSid)      $need['sambaSID'] = $wantSid;
        if ((int)($curType ?? 0) !== 2) $need['sambaGroupType'] = (string)$wantType;
        if ($curDisp !== $wantDisp)     $need['displayName'] = $wantDisp;

        if ($need) {
            if (!$CONFIRM) {
                $log('DBG', "PLAN-UPDATE: cn={$cn} ".json_encode($need, JSON_UNESCAPED_UNICODE));
            } else {
                // objectClass は既にある前提、属性置換のみ
                $ok = @ldap_mod_replace($ds, $dn, $need);
                if (!$ok) {
                    $log('ERR', "UPDATE失敗: {$dn} -> ".ldap_error($ds));
                    $stats['skip_ng']++; continue;
                }
                $log('UPD', "UPDATE: cn={$cn} (".implode(',', array_keys($need)).")");
            }
            $stats['updated']++;
        } else {
            $log('OK', "OK（整合済）: cn={$cn}");
            $stats['ok']++;
        }
        continue;
    }

    // 3) 新規付与（objectClass 追加 + 属性設定）
    LdapUtil::ensureGroupMapping(
        $ds, $dn, $wantSid, $wantDisp, 'domain', $CONFIRM,
        fn($m) => $log('ADD',  $m),
        fn($m) => $log('SKIP', $m)
    );
    if ($CONFIRM) $stats['added']++;
    else          $log('DBG', "PLAN-ADD: cn={$cn} gid={$wantGid} rid={$rid} sid={$wantSid}");
}

// ---------------- サマリ ----------------
$log('INFO', sprintf(
    "完了: total=%d ok=%d added=%d updated=%d skip(no-posix)=%d skip(ng)=%d",
    $stats['total'], $stats['ok'], $stats['added'], $stats['updated'],
    $stats['skip_no_posix'], $stats['skip_ng']
));
exit(0);

