#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_groupmap_add_level_groups.php
 *
 * 概要:
 *  - ou=Groups 配下の posixGroup に対して sambaGroupMapping を付与/整合
 *  - Domain SID を Users/Groups から推定
 *  - 既存 groupmap から rid = a*gid + b（整数）を推定、合わなければ「最大RID+1」から連番
 *
 * 使い方:
 *   php ldap_groupmap_add_level_groups.php --help
 *   php ldap_groupmap_add_level_groups.php [--confirm] [--verbose] [--group=<name>] [--ldap-uri=<URI>]
 */

// ===== 共通ライブラリ・オートローダ =====
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';

use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Support\GroupDef;   // 共通定義
use Tools\Ldap\Support\LdapUtil;   // 共通LDAPユーティリティ

const DEFAULT_LDAP_URI = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
const OU_GROUPS        = 'ou=Groups';

// ===== ヘルプ =====
function show_help(): void {
    $bin = basename(__FILE__);
    echo <<<TXT
{$bin} - posixGroup に sambaGroupMapping を付与/整合

USAGE:
  php {$bin} --help
  php {$bin} [--confirm] [--verbose] [--group=<name>] [--ldap-uri=<URI>]

OPTIONS:
  --help            このヘルプを表示
  --confirm         実際に変更を適用（省略時は DRY-RUN）
  --verbose         詳細ログを出力
  --group=NAME      対象グループを 1 件に限定（例: mgr-cls）
  --ldap-uri=URI    接続URIを明示

ENV:
  BASE_DN / LDAP_BASE_DN   例: dc=e-smile,dc=ne,dc=jp
  GROUPS_OU                例: ou=Groups,${BASE_DN}
  BIND_DN / BIND_PW        管理者 bind に使用（ldapi/EXTERNAL時は未使用）

NOTES:
  - posixGroup が存在しない場合は SKIP（先に ldap_level_groups_sync.php --init-group を実行）
  - RID は既存 groupmap の規則を優先。見つからない場合は最大RID+1 で付与
TXT;
}

// ===== ログ補助 =====
$T    = '[' . date('Y-m-d H:i:s') . ']';
$info = fn(string $m) => print("$T [INFO]  $m\n");
$warn = fn(string $m) => print(CliColor::yellow("$T [WARN]  $m\n"));
$errp = fn(string $m) => fwrite(STDERR, CliColor::red("$T [ERROR] $m\n"));

// ===== CLI 解析 =====
$opt = CliUtil::args($argv);
if (!empty($opt['help'])) { show_help(); exit(0); }

$CONFIRM  = !empty($opt['confirm']);
$VERBOSE  = !empty($opt['verbose']);
$ONLY_GRP = $opt['group'] ?? null;
$LDAP_URI = $opt['ldap-uri'] ?? (getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: DEFAULT_LDAP_URI);

// ===== 環境 =====
$baseDn = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$groups = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$bindDn = Env::get('BIND_DN', null, "cn=Admin,{$baseDn}");
$bindPw = Env::get('BIND_PW', null, "");

if (!$CONFIRM) echo CliColor::boldCyan("[DRY-RUN] use --confirm to write changes\n");
$info("BASE_DN=$baseDn GROUPS_OU=$groups URI=$LDAP_URI");

// ===== Main =====
try {
    // 接続/Bind
    Connection::init($LDAP_URI);
    $ds = Connection::connect();
    Connection::bind($ds, $bindDn, $bindPw);

    // Domain SID 推定
    $domSid = LdapUtil::inferDomainSid($ds, $baseDn);
    if (!$domSid) throw new RuntimeException("Domain SID could not be inferred (no sambaSID under Users/Groups).");
    if ($VERBOSE) $info("Domain SID = {$domSid}");

    // 既存 groupmap → rid 公式推定
    [$pairs, $ridList] = LdapUtil::collectGidRidPairs($ds, $groups, $domSid);
    [$a,$b] = LdapUtil::inferRidFormula($pairs);
    $nextRid = $ridList ? (max($ridList)+1) : 10000;

    if ($a !== null) $info("RID formula inferred: rid = {$a} * gid + {$b}");
    else             $info("RID formula not inferred; fallback to NEXT RID sequence (start={$nextRid})");

    // 対象セット
    $GROUP_DEF = GroupDef::all();
    $targets = $GROUP_DEF;
    if ($ONLY_GRP !== null) {
        $one = GroupDef::byName($ONLY_GRP);
        if (!$one) throw new InvalidArgumentException("--group={$ONLY_GRP} は定義に存在しません");
        $targets = [$one];
    }

    // マッピング適用
    $ok=0; $skip=0; $upd=0;
    foreach ($targets as $g) {
        $cn   = $g['name'];
        $gid  = (int)$g['gid'];
        $disp = $g['display'];
        $type = '2';
        $dn   = "cn={$cn}," . OU_GROUPS . ",{$baseDn}";

        if (!LdapUtil::dnExists($ds, $dn)) {
            $skip++; $warn("SKIP (no posixGroup): {$cn} (gid={$gid})");
            continue;
        }

        // 既存チェック
        $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['objectClass','sambaSID','displayName','sambaGroupType','gidNumber']);
        $e  = $sr ? ldap_get_entries($ds, $sr) : null;
        $hasMap=false;
        if ($e && ($e['count'] ?? 0) > 0) {
            $attrs  = $e[0];
            $oclist = isset($attrs['objectclass']) ? array_map('strtolower', $attrs['objectclass']) : [];
            $hasMap = in_array('sambagroupmapping', $oclist, true);
            if ($hasMap) {
                // displayName差分があれば整える
                $curDisp = $attrs['displayname'][0] ?? '';
                if ($disp !== '' && $disp !== $curDisp) {
                    if ($CONFIRM) {
                        @ldap_mod_replace($ds, $dn, ['displayName'=>$disp]);
                        $upd++;
                        $info("SET displayName: {$cn} => {$disp}");
                    } else {
                        $info("DRY: SET displayName: {$cn} => {$disp}");
                    }
                } else {
                    if ($VERBOSE) $info("OK (already mapped): {$cn} (gid={$gid})");
                }
                $ok++;
                continue;
            }
        }

        // RID 決定
        $rid = ($a !== null) ? ($a*$gid + $b) : $nextRid++;
        $sid = "{$domSid}-{$rid}";

        // 付与
        LdapUtil::ensureGroupMapping($ds, $dn, $sid, $disp, $type, $CONFIRM, $info, $warn);
        $ok++;
    }

    // 結果
    $summary = sprintf("[SUMMARY] targets=%d ok=%d updated=%d skip(no-posixGroup)=%d %s\n",
        count($targets), $ok, $upd, $skip, $CONFIRM ? '' : '(planned)');
    echo CliColor::boldGreen($summary);

    Connection::close($ds);
    exit(0);

} catch (\Throwable $e) {
    $errp($e->getMessage());
    exit(1);
}


