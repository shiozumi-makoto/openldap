#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_level_groups_sync.php
 *
 * 概要:
 *  - Tools\Ldap\Support\GroupDef::DEF（なければローカル定義）を唯一の正として、
 *    ou=Groups に posixGroup (cn/gidNumber) を作成/整合します。
 *  - --init-group で作成（無ければ追加・あれば gidNumber を整合）
 *
 * 使い方:
 *   php ldap_level_groups_sync.php --help
 *   php ldap_level_groups_sync.php --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]
 *
 * 既定:
 *   - DN は常に cn=<name>,ou=Groups,<BASE_DN> を使用（※実環境優先）
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';

use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Lib\CliColor;

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
  --ldap-uri=URI    接続URIを明示（既定: ldapi:///var/run/ldapi）
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
$info = fn(string $m) => print($ts() . " [INFO]  $m\n");
$warn = fn(string $m) => print(CliColor::yellow($ts() . " [WARN]  $m\n"));
$errp = fn(string $m) => fwrite(STDERR, CliColor::red($ts() . " [ERROR] $m\n"));

/* ===================== GroupDef（フェイルセーフ） ===================== */
/**
 * 1) Tools\Ldap\Support\GroupDef::DEF があればそれを使用
 * 2) 無ければローカル定義（あなたの希望 DEF と完全一致）で補完
 */
function _local_group_def(): array {
    return [
        [ 'name' => 'adm-cls', 'gid' => 3001, 'min' => 1,  'max' => 2,
          'display' => 'Administrator Class (1–2) / 管理者階層' ],
        [ 'name' => 'dir-cls', 'gid' => 3003, 'min' => 3,  'max' => 4,
          'display' => 'Director Class (3–4) / 取締役階層' ],
        [ 'name' => 'mgr-cls', 'gid' => 3005, 'min' => 5,  'max' => 5,
          'display' => 'Manager Class (5) / 部門長' ],
        [ 'name' => 'mgs-cls', 'gid' => 3006, 'min' => 6,  'max' => 14,
          'display' => 'Sub-Manager Class (6–14) / 課長・監督職' ],
        [ 'name' => 'stf-cls', 'gid' => 3015, 'min' => 15, 'max' => 19,
          'display' => 'Staff Class (15–19) / 主任・一般社員' ],
        [ 'name' => 'ent-cls', 'gid' => 3020, 'min' => 20, 'max' => 20,
          'display' => 'Entry Class (20) / 新入社員' ],
        [ 'name' => 'tmp-cls', 'gid' => 3021, 'min' => 21, 'max' => 98,
          'display' => 'Temporary Class (21–98) / 派遣・退職者' ],
        [ 'name' => 'err-cls', 'gid' => 3099, 'min' => 99, 'max' => 9999,
          'display' => 'Error Class (99) / 例外処理・未定義ID用' ],
    ];
}

function groupdef_all(): array {
    if (class_exists('Tools\\Ldap\\Support\\GroupDef')) {
        // まず CONST DEF を見る（今回のクラス定義はここに入っている）
        $ref = new \ReflectionClass('Tools\\Ldap\\Support\\GroupDef');
        if ($ref->hasConstant('DEF')) {
            /** @var array $def */
            $def = $ref->getConstant('DEF');
            if (is_array($def) && !empty($def)) return $def;
        }
        // フォールバックで all() / byName() があれば構築
        if (method_exists('Tools\\Ldap\\Support\\GroupDef','all')) {
            $all = \Tools\Ldap\Support\GroupDef::all();
            if (is_array($all) && !empty($all)) return $all;
        }
        if (method_exists('Tools\\Ldap\\Support\\GroupDef','byName')) {
            $acc = [];
            foreach (_local_group_def() as $row) {
                $x = \Tools\Ldap\Support\GroupDef::byName($row['name']);
                $acc[] = (is_array($x) && isset($x['gid'])) ? $x : $row;
            }
            return $acc;
        }
    }
    return _local_group_def();
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
        $gid = (int)$g['gid'];
        $nm  = (string)$g['name'];
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
    // 定義取り込み & 静的検査
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
            $dn  = "cn={$cn}," . OU_GROUPS . ",{$baseDn}"; // ← 常に ou=Groups を使用

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
