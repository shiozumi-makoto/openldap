#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_level_groups_sync.php
 *
 * 概要:
 *  - $GROUP_DEF（level 範囲・gid・表示名）をもとに、グループ運用を標準化
 *  - --init-group で ou=Groups に posixGroup を作成/整合（存在しない場合のみ作成、gidNumber差分は修正）
 *  - （将来拡張）level_id → クラス名への判定ヘルパを提供（classify / classify_info）
 *
 * 接続:
 *  - 環境変数 LDAP_URL / LDAP_URI / LDAPURI を優先
 *    * ldapi://%2F... は SASL/EXTERNAL
 *    * ldap://host は StartTLS 後に bind
 *    * ldaps://host はそのまま bind
 *
 * 使い方:
 *   php ldap_level_groups_sync.php --help
 *   php ldap_level_groups_sync.php --init-group [--group=mgr-cls] [--confirm] [--verbose]
 *
 * 主要オプション:
 *   --help            : このヘルプを表示して終了
 *   --confirm         : 実際に更新を行う（省略時は DRY-RUN）
 *   --verbose         : 詳細ログ
 *   --ldap-uri=URI    : 接続 URI を明示（未指定時は環境変数/既定を使用）
 *   --group=NAME      : 対象グループを 1 つに限定（例: --group=mgr-cls）
 *   --init-group      : ou=Groups に posixGroup を作成/整合（cn/gidNumber）
 *
 * 必要な環境変数（なければ既定値）:
 *   BASE_DN / LDAP_BASE_DN   例: dc=e-smile,dc=ne,dc=jp
 *   GROUPS_OU                例: ou=Groups,${BASE_DN}
 *   BIND_DN / BIND_PW        管理者 bind に利用（ldapi/EXTERNAL時は未使用）
 */

// ===== 共通ライブラリ・オートローダ =====
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/ldap_cli_uri_switch.inc.php';

use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;

// ====== 定義（あなたの指定をそのまま使用） ======
const DEFAULT_LDAP_URI = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
const OU_GROUPS        = 'ou=Groups';

$GROUP_DEF = [
    [ 'name' => 'adm-cls',  'gid' => 3001, 'min' => 1,  'max' => 2,  'display' => 'Administrator Class 1–2' ],
    [ 'name' => 'dir-cls',  'gid' => 3003, 'min' => 3,  'max' => 4,  'display' => 'Director Class 3–4' ],
    [ 'name' => 'mgr-cls',  'gid' => 3005, 'min' => 5,  'max' => 5,  'display' => 'Manager Class 5' ],
    [ 'name' => 'mgs-cls',  'gid' => 3006, 'min' => 6,  'max' => 14, 'display' => 'Sub-Manager Class 6–14' ],
    [ 'name' => 'stf-cls',  'gid' => 3016, 'min' => 15, 'max' => 19, 'display' => 'Senior Staff Class 15–19' ],
    [ 'name' => 'ent-cls',  'gid' => 3020, 'min' => 20, 'max' => 20, 'display' => 'Entry (Employee) Class 20' ],
    [ 'name' => 'tmp-cls',  'gid' => 3021, 'min' => 21, 'max' => 99, 'display' => 'Retired (Temporary) Class 21–99' ],
];

// ====== ヘルプ ======
function show_help(): void {
    $bin = basename(__FILE__);
    echo <<<TXT
{$bin} - Level定義ベースのグループ運用補助

USAGE:
  php {$bin} --help
  php {$bin} --init-group [--group=<name>] [--confirm] [--verbose] [--ldap-uri=<URI>]

OPTIONS:
  --help            このヘルプを表示して終了
  --confirm         実際に変更を適用（省略時は DRY-RUN）
  --verbose         詳細ログを出力
  --ldap-uri=URI    接続URIを明示（例: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi）
  --group=NAME      対象グループを 1 つに限定（例: --group=mgr-cls）
  --init-group      ou=Groups に posixGroup を作成/整合（cn/gidNumber）

ENV:
  BASE_DN / LDAP_BASE_DN   ベースDN（既定: dc=e-smile,dc=ne,dc=jp）
  GROUPS_OU                既定: ou=Groups,${BASE_DN}
  BIND_DN / BIND_PW        管理者バインドに使用（ldapi/EXTERNAL時は不要）

NOTES:
  - Sambaの groupmap 付与は別スクリプト（ldap_groupmap_add_level_groups.php）で実施してください。
  - 本ツールは posixGroup の存在/属性整合（cn/gidNumber）にフォーカスします。

TXT;
}

// ====== 小ユーティリティ ======
$T   = '['.date('Y-m-d H:i:s').']';
$info = fn(string $m) => print("$T [INFO]  $m\n");
$warn = fn(string $m) => print(CliColor::yellow("$T [WARN]  $m\n"));
$errp = fn(string $m) => fwrite(STDERR, CliColor::red("$T [ERROR] $m\n"));

/** DN が存在するか */
function dn_exists($ds, string $dn): bool {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['dn']);
    if ($sr === false) return false;
    $e = ldap_get_entries($ds, $sr);
    return ($e && ($e['count'] ?? 0) > 0);
}

/** posixGroup を作成 */
function add_posix_group($ds, string $dn, string $cn, int $gid, bool $confirm, callable $info): void {
    if (!$confirm) {
        $info("DRY: add posixGroup dn=$dn cn=$cn gidNumber=$gid");
        return;
    }
    $entry = [
        'objectClass' => ['top','posixGroup'],
        'cn'          => $cn,
        'gidNumber'   => (string)$gid,
    ];
    if (!@ldap_add($ds, $dn, $entry)) {
        throw new RuntimeException("ldap_add failed: ".ldap_error($ds));
    }
    $info("ADD posixGroup: $cn (gid=$gid)");
}

/** gidNumber 差分があれば修正 */
function ensure_gid_number($ds, string $dn, int $want, bool $confirm, callable $info): void {
    $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['gidNumber']);
    if ($sr === false) return;
    $e = ldap_get_entries($ds, $sr);
    if (!$e || ($e['count'] ?? 0) === 0) return;
    $cur = isset($e[0]['gidnumber'][0]) ? (int)$e[0]['gidnumber'][0] : null;
    if ($cur === null || $cur === $want) return;

    if (!$confirm) {
        $info("DRY: replace gidNumber on $dn  {$cur} => {$want}");
        return;
    }
    if (!@ldap_mod_replace($ds, $dn, ['gidNumber' => (string)$want])) {
        throw new RuntimeException("ldap_mod_replace(gidNumber) failed: ".ldap_error($ds));
    }
    $info("SET gidNumber: {$cur} => {$want} ($dn)");
}

/** $GROUP_DEF から name 指定で1件取得 */
function groupdef_by_name(array $def, string $name): ?array {
    foreach ($def as $g) if ($g['name'] === $name) return $g;
    return null;
}

/** レベル→クラス名 */
function classify(int $lv, array $def): ?string {
    foreach ($def as $g) if ($lv >= $g['min'] && $lv <= $g['max']) return $g['name'];
    return null;
}

/** レベル→グループ情報 */
function classify_info(int $lv, array $def): ?array {
    foreach ($def as $g) if ($lv >= $g['min'] && $lv <= $g['max']) return $g;
    return null;
}

// ====== CLI 解析 ======
$opt      = CliUtil::args($argv);
if (!empty($opt['help'])) { show_help(); exit(0); }

$CONFIRM  = !empty($opt['confirm']);
$VERBOSE  = !empty($opt['verbose']);
$LDAP_URI = $opt['ldap-uri'] ?? (getenv('LDAPURI') ?: getenv('LDAP_URI') ?: getenv('LDAP_URL') ?: DEFAULT_LDAP_URI);
$ONLY_GRP = $opt['group'] ?? null;

$doInitGroup = !empty($opt['init-group']);   // ★今回追加した機能

// ====== 環境 ======
$baseDn = Env::get('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$groups = Env::get('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$bindDn = Env::get('BIND_DN', null, "cn=Admin,{$baseDn}");
$bindPw = Env::get('BIND_PW', null, "");

// 起動表示
if (!$CONFIRM) {
    CliColor::println(CliColor::boldBlue("[DRY-RUN] use --confirm to write changes"));
}
$info("BASE_DN=$baseDn GROUPS_OU=$groups URI=$LDAP_URI");

// ====== Main ======
try {
    // 接続
    Connection::init($LDAP_URI);
    $ds = Connection::connect();
    Connection::bind($ds, $bindDn, $bindPw);

    // 対象セット
    $targets = $GROUP_DEF;
    if ($ONLY_GRP !== null) {
        $one = groupdef_by_name($GROUP_DEF, $ONLY_GRP);
        if (!$one) {
            throw new InvalidArgumentException("--group={$ONLY_GRP} は定義に存在しません");
        }
        $targets = [$one];
    }

    $t_total = count($targets);
    $t_add   = 0;
    $t_fix   = 0;
    $t_skip  = 0;

    if ($doInitGroup) {
        // === posixGroup の作成/整合 ===
        foreach ($targets as $g) {
            $cn  = $g['name'];
            $gid = (int)$g['gid'];
            $dn  = "cn={$cn}," . OU_GROUPS . ",{$baseDn}";

            if (!dn_exists($ds, $dn)) {
                add_posix_group($ds, $dn, $cn, $gid, $CONFIRM, $info);
                $t_add++;
            } else {
                ensure_gid_number($ds, $dn, $gid, $CONFIRM, $info);
                $t_fix++;
            }
        }
        $summary = sprintf("[SUMMARY:init-group] targets=%d added=%d fixed(gid)=%d %s\n",
            $t_total, $t_add, $t_fix, $CONFIRM ? '' : '(planned)');
        CliColor::println(CliColor::boldGreen($summary));
        Connection::close($ds);
        exit(0);
    }

    // ここから先は、将来的な “level→グループ同期” 本体の拡張用フック
    // 今回のご要望は --help と --init-group の実装なので処理はここまで。
    $warn("No operation specified. Use --init-group or see --help.");
    Connection::close($ds);
    exit(0);

} catch (\Throwable $e) {
    $errp($e->getMessage());
    exit(1);
}
