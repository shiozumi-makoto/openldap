#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_level_groups_sync.php
 *
 * 概要:
 *  - $GROUP_DEF（level 範囲・gid・display）に基づき、ou=Groups に posixGroup を作成/整合
 *  - --init-group で作成（なければ追加・あれば gidNumber を整合）
 *
 * 使い方:
 *   php ldap_level_groups_sync.php --help
 *   php ldap_level_groups_sync.php --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]
 */

// ===== 共通ライブラリ・オートローダ =====
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';

use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Support\GroupDef;   // 共通定義

const DEFAULT_LDAP_URI = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
const OU_GROUPS        = 'ou=Groups';

// ===== ヘルプ =====
function show_help(): void {
    $bin = basename(__FILE__);
    echo <<<TXT
{$bin} - Level定義ベースのグループ運用補助

USAGE:
  php {$bin} --help
  php {$bin} --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]

OPTIONS:
  --help            このヘルプを表示
  --confirm         実際に変更を適用（省略時は DRY-RUN）
  --verbose         詳細ログを出力
  --ldap-uri=URI    接続URIを明示
  --group=NAME      対象グループを 1 件に限定（例: mgr-cls）
  --init-group      ou=Groups に posixGroup を作成/整合（cn/gidNumber）

ENV:
  BASE_DN / LDAP_BASE_DN   例: dc=e-smile,dc=ne,dc=jp
  GROUPS_OU                例: ou=Groups,${BASE_DN}
  BIND_DN / BIND_PW        管理者 bind に使用（ldapi/EXTERNAL時は未使用）
TXT;
}

// ===== ログ補助 =====
$T   = '[' . date('Y-m-d H:i:s') . ']';
$info = fn(string $m) => print("$T [INFO]  $m\n");
$warn = fn(string $m) => print(CliColor::yellow("$T [WARN]  $m\n"));
$errp = fn(string $m) => fwrite(STDERR, CliColor::red("$T [ERROR] $m\n"));

// ===== 便利関数（このスクリプト専用）=====
function dn_exists($ds, string $dn): bool {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['dn']);
    if ($sr === false) return false;
    $e = ldap_get_entries($ds, $sr);
    return ($e && ($e['count'] ?? 0) > 0);
}
function add_posix_group($ds, string $dn, string $cn, int $gid, bool $confirm, callable $info): void {
    if (!$confirm) { $info("DRY: add posixGroup dn=$dn cn=$cn gidNumber=$gid"); return; }
    $entry = ['objectClass'=>['top','posixGroup'], 'cn'=>$cn, 'gidNumber'=>(string)$gid];
    if (!@ldap_add($ds, $dn, $entry)) throw new RuntimeException("ldap_add failed: ".ldap_error($ds));
    $info("ADD posixGroup: $cn (gid=$gid)");
}
function ensure_gid_number($ds, string $dn, int $want, bool $confirm, callable $info): void {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['gidNumber']);
    if ($sr === false) return;
    $e = ldap_get_entries($ds, $sr);
    if (!$e || ($e['count'] ?? 0) === 0) return;
    $cur = isset($e[0]['gidnumber'][0]) ? (int)$e[0]['gidnumber'][0] : null;
    if ($cur === null || $cur === $want) return;
    if (!$confirm) { $info("DRY: replace gidNumber on $dn  {$cur} => {$want}"); return; }
    if (!@ldap_mod_replace($ds, $dn, ['gidNumber'=>(string)$want])) {
        throw new RuntimeException("ldap_mod_replace(gidNumber) failed: ".ldap_error($ds));
    }
    $info("SET gidNumber: {$cur} => {$want} ($dn)");
}

// ===== CLI 解析 =====
$opt = CliUtil::args($argv);
if (!empty($opt['help'])) { show_help(); exit(0); }

$CONFIRM    = !empty($opt['confirm']);
$VERBOSE    = !empty($opt['verbose']);
$LDAP_URI   = $opt['ldap-uri'] ?? (getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: DEFAULT_LDAP_URI);
$ONLY_GRP   = $opt['group'] ?? null;
$doInit     = !empty($opt['init-group']);

// ===== 環境 =====
$baseDn = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$groups = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$bindDn = Env::get('BIND_DN', null, "cn=Admin,{$baseDn}");
$bindPw = Env::get('BIND_PW', null, "");

if (!$CONFIRM) echo CliColor::boldCyan("[DRY-RUN] use --confirm to write changes\n");
$info("BASE_DN=$baseDn GROUPS_OU=$groups URI=$LDAP_URI");

// ===== Main =====
try {
    Connection::init($LDAP_URI);
    $ds = Connection::connect();
    Connection::bind($ds, $bindDn, $bindPw);

    $GROUP_DEF = GroupDef::all();
    $targets = $GROUP_DEF;
    if ($ONLY_GRP !== null) {
        $one = GroupDef::byName($ONLY_GRP);
        if (!$one) throw new InvalidArgumentException("--group={$ONLY_GRP} は定義に存在しません");
        $targets = [$one];
    }

    $t_total = count($targets); $t_add=0; $t_fix=0;

    if ($doInit) {
        foreach ($targets as $g) {
            $cn  = $g['name'];
            $gid = (int)$g['gid'];
            $dn  = "cn={$cn}," . OU_GROUPS . ",{$baseDn}";

            if (!dn_exists($ds, $dn)) { add_posix_group($ds,$dn,$cn,$gid,$CONFIRM,$info); $t_add++; }
            else                      { ensure_gid_number($ds,$dn,$gid,$CONFIRM,$info);   $t_fix++; }
        }
        $summary = sprintf("[SUMMARY:init-group] targets=%d added=%d fixed(gid)=%d %s\n",
            $t_total, $t_add, $t_fix, $CONFIRM ? '' : '(planned)');
        echo CliColor::boldGreen($summary);
        Connection::close($ds);
        exit(0);
    }

    $warn("No operation specified. Use --init-group or see --help.");
    Connection::close($ds);
    exit(0);

} catch (\Throwable $e) {
    $errp($e->getMessage());
    exit(1);
}

