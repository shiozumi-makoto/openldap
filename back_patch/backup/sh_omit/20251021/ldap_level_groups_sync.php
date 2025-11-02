#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_level_groups_sync.php
 *
 * 概要:
 *  - Tools\Ldap\Support\GroupDef::DEF を「唯一の正」として、
 *    ou=Groups に posixGroup (cn/gidNumber) を作成/整合します。
 *  - --init-group で作成（無ければ追加・あれば gidNumber を整合）
 *
 * 使い方:
 *   php ldap_level_groups_sync.php --help
 *   php ldap_level_groups_sync.php --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]
 *
 * 既定:
 *   - DN は cn=<name>,ou=Groups,<BASE_DN>
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';

use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Lib\CliColor;
use Tools\Ldap\Support\GroupDef;

const DEFAULT_LDAP_URI = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
const OU_GROUPS        = 'ou=Groups';

/* ===================== ヘルプ ===================== */
function show_help(): void {
    $bin = basename(__FILE__);
    echo <<<TXT
{$bin} - Level定義ベースのグループ運用補助（GroupDef::DEF に準拠）

USAGE:
  php {$bin} --help
  php {$bin} --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]

OPTIONS:
  --help            このヘルプを表示
  --confirm         実際に変更を適用（省略時は DRY-RUN）
  --verbose         詳細ログを出力
  --ldap-uri=URI    接続URIを明示（既定: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi）
  --group=NAME      対象グループを 1 件に限定（例: mgr-cls）
  --init-group      ou=Groups に posixGroup を作成/整合（cn/gidNumber）

ENV:
  BASE_DN / LDAP_BASE_DN   例: dc=e-smile,dc=ne,dc=jp
  GROUPS_OU                例: ou=Groups,${BASE_DN}  ※このツールは "ou=Groups" を前提
  BIND_DN / BIND_PW        管理者 bind に使用（ldapi/EXTERNAL時は未使用）
TXT;
}

/* ===================== ログ補助 ===================== */
$ts   = fn() => '[' . date('Y-m-d H:i:s') . ']';
$info = function (string $m) use ($ts) { print($ts() . " [INFO]  $m\n"); };
$warn = function (string $m) use ($ts) { print(CliColor::yellow($ts() . " [WARN]  $m\n")); };
$errp = function (string $m) use ($ts) { fwrite(STDERR, CliColor::red($ts() . " [ERROR] $m\n")); };

/* ===================== GroupDef: 唯一の正 ===================== */
/**
 * GroupDef::DEF のみを受け付ける。
 * 存在しない／空／配列以外 → 例外。
 */
function groupdef_all(): array {
    if (!class_exists('Tools\\Ldap\\Support\\GroupDef')) {
        throw new \RuntimeException('Tools\\Ldap\\Support\\GroupDef クラスが見つかりません。ライブラリを配置してください。');
    }
    if (!defined('Tools\\Ldap\\Support\\GroupDef::DEF')) {
        throw new \RuntimeException('GroupDef::DEF が未定義です。GroupDef に DEF 定義を追加してください。');
    }
    /** @var array $def */
    $def = \Tools\Ldap\Support\GroupDef::DEF;
    if (!is_array($def) || empty($def)) {
        throw new \RuntimeException('GroupDef::DEF が空、もしくは配列ではありません。正しい配列を定義してください。');
    }
    // 軽い構造バリデーション
    foreach ($def as $i => $g) {
        if (!is_array($g) || !isset($g['name'],$g['gid'])) {
            throw new \RuntimeException("GroupDef::DEF[$i] の形式が不正です（name/gid 必須）。");
        }
    }
    return $def;
}
function groupdef_by_name(string $name): ?array {
    foreach (groupdef_all() as $g) if (($g['name'] ?? null) === $name) return $g;
    return null;
}
/** DEF 内 gid 重複チェック（静的バリデーション） */
function assert_unique_gids(array $def): void {
    $seen = [];
    $dup  = [];
    foreach ($def as $g) {
        $gid = (int)($g['gid'] ?? -1);
        $nm  = (string)($g['name'] ?? '');
        if ($gid < 0 || $nm === '') continue;
        if (isset($seen[$gid])) $dup[$gid] = array_merge($dup[$gid] ?? [], [$nm]);
        else $seen[$gid] = [$nm];
    }
    if ($dup) {
        $msg = "GroupDef::DEF に gidNumber の重複があります: ";
        foreach ($dup as $gid => $names) {
            $msg .= sprintf("gid=%d => [%s]; ", $gid, implode(',', $names));
        }
        throw new \RuntimeException($msg);
    }
}

/* ===================== 便利関数 ===================== */
function dn_exists($ds, string $dn): bool {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['dn']);
    if ($sr === false) return false;
    $e = @ldap_get_entries($ds, $sr);
    return ($e && ($e['count'] ?? 0) > 0);
}

/**
 * groupDnByName() が存在しても、OU は必ず ou=Groups を強制。
 * groupDnByName() の戻りDNが ou=Groups を含む場合のみ採用、それ以外はツール既定DNを返す。
 */
function pick_group_dn(string $cn, string $baseDn): string {
    $fallback = "cn={$cn}," . OU_GROUPS . ",{$baseDn}";
    if (class_exists('Tools\\Ldap\\Support\\GroupDef') &&
        method_exists('Tools\\Ldap\\Support\\GroupDef','groupDnByName')) {
        try {
            $cand = \Tools\Ldap\Support\GroupDef::groupDnByName($cn, $baseDn);
            if (is_string($cand) && preg_match('/,ou=Groups,/i', $cand)) {
                return $cand;
            }
        } catch (\Throwable $e) {
            // 無視してフォールバック
        }
    }
    return $fallback;
}

function add_posix_group($ds, string $dn, string $cn, int $gid, bool $confirm, callable $info, bool $verbose): void {
    if (!$confirm) { $info("DRY: add posixGroup dn=$dn cn=$cn gidNumber=$gid"); return; }
    $entry = ['objectClass'=>['top','posixGroup'], 'cn'=>$cn, 'gidNumber'=>(string)$gid];
    if (!@ldap_add($ds, $dn, $entry)) throw new RuntimeException("ldap_add failed: ".ldap_error($ds));
    $info("ADD posixGroup: $cn (gid=$gid)");
    if ($verbose) $info(" → dn=$dn");
}

/**
 * 変更があった場合のみ true を返す
 */
function ensure_gid_number($ds, string $dn, int $want, bool $confirm, callable $info): bool {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['gidNumber']);
    if ($sr === false) return false;
    $e = ldap_get_entries($ds, $sr);
    if (!$e || ($e['count'] ?? 0) === 0) return false;
    $cur = isset($e[0]['gidnumber'][0]) ? (int)$e[0]['gidnumber'][0] : null;
    if ($cur === null || $cur === $want) return false; // 変更なし
    if (!$confirm) { $info("DRY: replace gidNumber on $dn  {$cur} => {$want}"); return true; }
    if (!@ldap_mod_replace($ds, $dn, ['gidNumber'=>(string)$want])) {
        throw new RuntimeException("ldap_mod_replace(gidNumber) failed: ".ldap_error($ds));
    }
    $info("SET gidNumber: {$cur} => {$want} ($dn)");
    return true; // 変更あり
}

/* ===================== CLI 解析 ===================== */
$opt = \Tools\Lib\CliUtil::args($argv);
if (!empty($opt['help'])) { show_help(); exit(0); }

$CONFIRM    = !empty($opt['confirm']);
$VERBOSE    = !empty($opt['verbose']);
$LDAP_URI   = $opt['ldap-uri'] ?? (getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: DEFAULT_LDAP_URI);
$ONLY_GRP   = $opt['group'] ?? null;
$doInit     = !empty($opt['init-group']);

/* ===================== 環境 ===================== */
$baseDn   = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$groupsOu = Env::get('GROUPS_OU', null, OU_GROUPS . ",{$baseDn}"); // 例: ou=Groups,dc=...
$bindDn   = Env::get('BIND_DN', null, "cn=Admin,{$baseDn}");
$bindPw   = Env::get('BIND_PW', null, "");

if (!$CONFIRM) echo CliColor::boldCyan("[DRY-RUN] use --confirm to write changes\n");
$info("BASE_DN=$baseDn GROUPS_OU=$groupsOu URI=$LDAP_URI");

/* ===================== Main ===================== */
try {
    // 定義取り込み & 静的検査（DEFのみ）
    $GROUP_DEF = groupdef_all();
    assert_unique_gids($GROUP_DEF);

    // 対象絞り込み
    $targets = $GROUP_DEF;
    if ($ONLY_GRP !== null) {
        $one = groupdef_by_name($ONLY_GRP);
        if (!$one) throw new InvalidArgumentException("--group={$ONLY_GRP} は GroupDef::DEF に存在しません");
        $targets = [$one];
    }

    Connection::init($LDAP_URI);
    $ds = Connection::connect();
    // ldapi/EXTERNAL でも bind は通る（Authzは slapd 側設定次第）。失敗時は例外に。
    Connection::bind($ds, $bindDn, $bindPw);

    $t_total = count($targets); $t_add=0; $t_fix=0;

    if ($doInit) {
        foreach ($targets as $g) {
            if (!is_array($g) || !isset($g['name']) || !isset($g['gid'])) continue;
            $cn  = (string)$g['name'];
            $gid = (int)$g['gid'];
            $dn  = pick_group_dn($cn, $baseDn); // ou=Groups を強制
            if (!dn_exists($ds, $dn)) {
                add_posix_group($ds, $dn, $cn, $gid, $CONFIRM, $info, $VERBOSE);
                $t_add++;
            } else {
                if (ensure_gid_number($ds, $dn, $gid, $CONFIRM, $info)) {
                    $t_fix++;
                } elseif ($VERBOSE) {
                    $info("OK: gidNumber already {$gid} ($dn)");
                }
            }
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


