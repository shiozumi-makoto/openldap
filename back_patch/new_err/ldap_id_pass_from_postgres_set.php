#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php
 *   - PostgreSQL（passwd_tnas / 情報個人 など）から LDAP(ou=Users) にユーザ属性を同期
 *   - employeeType を安全に設定（空値のときは上書きしない）
 *   - People OU は ou=Users 固定（ou=People は探索しない）
 *   - --ldap を付けたときのみ LDAP 書き込み
 *   - --confirm を付けたときのみ実際に APPLY（なければ dry-run 表示）
 *   - --init は初期化動作のヒント（既存再設定を許容）※破壊的削除は行わない
 *
 * 依存:
 *   require __DIR__ . '/autoload.php';
 *   use Tools\Lib\Config;
 *   use Tools\Lib\CliUtil;
 *   use Tools\Lib\LdapConnector;
 *   use Tools\Lib\Env;
 *   use Tools\Ldap\Support\GroupDef;
 */

require __DIR__ . '/autoload.php';

use Tools\Lib\Config;
use Tools\Lib\CliUtil;
use Tools\Lib\LdapConnector;
use Tools\Lib\Env;
use Tools\Ldap\Support\GroupDef;

// ---------------------------------------------------------------
// CLI パース
// ---------------------------------------------------------------
$args = CliUtil::parse($argv ?? [], [
    // 接続
    'ldapi'     => ['type'=>'flag',   'default'=>false],
    'ldaps'     => ['type'=>'string', 'default'=>null],     // 例: ovs-012.e-smile.local
    'uri'       => ['type'=>'string', 'default'=>null],
    // 動作
    'ldap'      => ['type'=>'flag',   'default'=>false],    // LDAP 書き込み有効化
    'confirm'   => ['type'=>'flag',   'default'=>false],    // 実行
    'init'      => ['type'=>'flag',   'default'=>false],    // 初期化モード（破壊はしない）
    'list'      => ['type'=>'flag',   'default'=>false],    // 対象のみ表示
    'verbose'   => ['type'=>'flag',   'default'=>false],
    // DB
    'pg-host'   => ['type'=>'string', 'default'=>Env::get('PG_HOST','127.0.0.1')],
    'pg-db'     => ['type'=>'string', 'default'=>Env::get('PG_DB','es_dev')],
    'pg-user'   => ['type'=>'string', 'default'=>Env::get('PG_USER','postgres')],
    'pg-pass'   => ['type'=>'string', 'default'=>Env::get('PG_PASS','')],
    'pg-port'   => ['type'=>'int',    'default'=>intval(Env::get('PG_PORT','5432'))],
]);

$LDAPI   = (bool)$args['ldapi'];
$LDAPS   = $args['ldaps'];
$URI     = $args['uri'];
$DO_LDAP = (bool)$args['ldap'];
$CONFIRM = (bool)$args['confirm'];
$INIT    = (bool)$args['init'];
$LIST    = (bool)$args['list'];
$VERBOSE = (bool)$args['verbose'];

$PG_HOST = (string)$args['pg-host'];
$PG_DB   = (string)$args['pg-db'];
$PG_USER = (string)$args['pg-user'];
$PG_PASS = (string)$args['pg-pass'];
$PG_PORT = (int)$args['pg-port'];

// ログ関数
$info = static function(string $m) use ($VERBOSE){ if($VERBOSE) echo "[INFO] {$m}\n"; };
$dbg  = static function(string $m) use ($VERBOSE){ if($VERBOSE) echo "[DBG]  {$m}\n"; };
$warn = static function(string $m){ echo "[WARN] {$m}\n"; };
$err  = static function(string $m){ fwrite(STDERR, "[ERR]  {$m}\n"); };

// ---------------------------------------------------------------
echo "=== LDAP connection summary ===\n";
$mode = $LDAPI ? 'ldapi' : ($LDAPS ? 'ldaps' : ($URI ? 'uri' : 'ldapi'));
echo " Mode      : {$mode}\n";
$uri = $URI ?: ($LDAPS ? "ldaps://{$LDAPS}:636" : "ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi");
echo " URI       : {$uri}\n";

// LdapConnector 経由で接続
$lc = new LdapConnector($uri);
$ds = $lc->connect(); // 例外は内部で投げる想定
$lc->bindSaslExternalIfPossible(); // EXTERNAL が使えない時は匿名/適切な手段にフォールバックする実装想定
echo " Status    : " . ($lc->isBound() ? "bind success" : "bind ?") . "\n";
echo " Protocol  : v3\n";
echo "===============================\n\n";

// baseDn 取得（LdapConnector で取得可能、なければ既知固定）
$baseDn = $lc->getBaseDn();
if (!$baseDn) {
    // 既知の固定
    $baseDn = 'dc=e-smile,dc=ne,dc=jp';
}
echo "[INFO] BASE_DN: {$baseDn}\n";

// People OU は ou=Users 固定（ou=People は探索しない）
$peopleOu = "ou=Users,{$baseDn}";
echo "[INFO] People OU: {$peopleOu}（固定）\n";

// ---------------------------------------------------------------
// 同期する HOME/LDAP（HOME はログのみ、LDAP は --ldap 指定時のみ）
// ---------------------------------------------------------------
$HOST      = trim((string)shell_exec('hostname -f')) ?: 'localhost.localdomain';
$HOME_ROOT = '/ovs012_home';
$SKEL      = '/etc/skel';
$MODE      = 0750;
$LOG_FILE  = 'ldap_uid_errors.log';

echo "\n=== START add-home(+LDAP) ===\n";
echo "HOST      : {$HOST}\n";
echo "HOME_ROOT : {$HOME_ROOT}\n";
echo "SKEL      : {$SKEL}\n";
printf("MODE      : %04o (%d)\n", $MODE, $MODE);
echo "CONFIRM   : " . ($CONFIRM ? "YES (execute)" : "NO  (dry-run)") . "\n";
echo "LDAP      : " . ($DO_LDAP ? "enabled" : "disabled") . "\n";
echo "log file  : {$LOG_FILE}\n";
echo "local uid : " . (int)posix_geteuid() . "\n";
echo "----------------------------------------------\n";
echo "ldap_host : {$uri}\n";
echo "ldap_base : {$baseDn}\n";
echo "ldap_user : \n";
echo "ldap_pass : --------\n";
echo "----------------------------------------------\n\n";

// ---------------------------------------------------------------
// DB 取得
// ---------------------------------------------------------------
$dbg("PDO connect pgsql:host={$PG_HOST};port={$PG_PORT};dbname={$PG_DB}");
$pdo = new PDO(
    "pgsql:host={$PG_HOST};port={$PG_PORT};dbname={$PG_DB}",
    $PG_USER,
    $PG_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// 対象行（ここはあなたのスキーマに合わせて調整）
// - passwd_tnas: cmp_id, user_id, level_id, login_id, samba_id 等
// - 情報個人   : 姓/名/かな/表示名 など（左外部結合）
$sql = <<<SQL
SELECT
  p.cmp_id,
  p.user_id,
  p.level_id,
  COALESCE(p.login_id, FORMAT('%02d-%03d', p.cmp_id, p.user_id)) AS login_id,
  p.samba_id,
  i."姓"           AS last_name,
  i."名"           AS first_name,
  i."姓かな"       AS last_kana,
  i."名かな"       AS first_kana,
  i."表示名"       AS display_name
FROM public.passwd_tnas p
LEFT JOIN public."情報個人" i
  ON i.cmp_id = p.cmp_id AND i.user_id = p.user_id
WHERE 1=1
ORDER BY p.cmp_id, p.user_id
SQL;

$rows = $pdo->query($sql)->fetchAll();
echo "DB: fetch target rows …\n";
echo "[INFO] DB rows (pre-filter): " . count($rows) . "\n";

// ここで必要ならフィルタ（例: 退職者除外など）
// 今回はそのまま
$targets = $rows;
echo "[INFO] DB rows (post-filter): " . count($targets) . "\n";

// People OU の存在・件数チェック（安全ガード）
$sr = @ldap_search($ds, $peopleOu, '(objectClass=posixAccount)', ['uid'], 0, 1, 5);
if (!$sr) {
    $warn("People OU={$peopleOu} に posixAccount が見つからない/検索できない可能性があります（処理は続行）");
} else {
    $cnt = ldap_count_entries($ds, $sr);
    $info("People OU posixAccount count: {$cnt}");
}

// Samba ドメイン SID を参考表示（なくても動作は可）
$domainSid = inferDomainSid($ds, $baseDn);
if ($domainSid) {
    echo "[INFO] Domain SID: {$domainSid}\n";
}

if ($INIT) {
    echo "=== INIT MODE ===\n";
}

// ---------------------------------------------------------------
// メイン処理
// ---------------------------------------------------------------
$applied = 0;
$listed  = 0;
foreach ($targets as $row) {
    $uid      = (string)$row['login_id'];
    $cmpId    = (int)$row['cmp_id'];
    $userId   = (int)$row['user_id'];
    $levelId  = (int)$row['level_id'];
    $samba_id = (string)($row['samba_id'] ?? '');

    // LDAP DN（ou=Users 固定）
    $dn = "uid={$uid},{$peopleOu}";

    // 画面出力（短縮）
    $label = sprintf("[%02d-%03d] [%s%3s] [%-20s]", $cmpId, $userId, groupNameFromLevel($levelId), levelSuffix($levelId), pad($uid,20));

    // 既存取得
    $exists = entryExists($ds, $dn);
    $dbg(($exists?'EXIST':'NEW ') . " DN={$dn}");

    // 反映モッズ
    $mods = buildUserMods($row, $exists, $peopleOu, $ds, $baseDn, $VERBOSE);

    if ($LIST) {
        echo "Up!  {$label} [LIST] 予定属性: " . implode(',', array_map(static fn($m)=>$m['attr'], $mods)) . "\n";
        $listed++;
        continue;
    }

    if (!$DO_LDAP) {
        echo "Up!  {$label} [DRY]  更新スキップ（--ldap 未指定）\n";
        continue;
    }

    if (!$CONFIRM) {
        echo "Up!  {$label} [DRY]  書込予定（--confirm なし）: " . implode(',', array_map(static fn($m)=>$m['attr'], $mods)) . "\n";
        continue;
    }

    // APPLY
    if (empty($mods)) {
        echo "Up!  {$label} [SKIP] 更新なし\n";
        continue;
    }

    $ok = @ldap_modify_batch($ds, $dn, $mods);
    if (!$ok) {
        // 新規の場合は add に切替（必要なら属性組み直し）
        if (!$exists) {
            $attrs = modsToAddAttrs($mods, $row);
            $okAdd = @ldap_add($ds, $dn, $attrs);
            if ($okAdd) {
                echo "Up!  {$label} [ADD]  作成\n";
                $applied++;
            } else {
                $errMsg = ldap_error($ds);
                $err("ADD 失敗: {$dn} : {$errMsg}");
            }
        } else {
            $errMsg = ldap_error($ds);
            $err("MOD 失敗: {$dn} : {$errMsg}");
        }
    } else {
        echo "Up!  {$label} [CON] 更新 " . summariseMods($mods) . "\n";
        $applied++;
    }
}

echo "=== DONE ===\n";
if ($LIST)  echo "[LIST] {$listed} rows listed\n";
if ($DO_LDAP && $CONFIRM) echo "[APPLIED] {$applied} rows modified/added\n";

// ===============================================================
// 関数群
// ===============================================================

/**
 * 既存 DN の有無
 */
function entryExists($ds, string $dn): bool {
    $res = @ldap_read($ds, $dn, '(objectClass=*)', ['dn'], 0, 1, 3);
    return (bool)($res && ldap_count_entries($ds, $res) > 0);
}

/**
 * Domain SID を推定（存在すれば）※なくてもOK
 */
function inferDomainSid($ds, string $baseDn): ?string {
    // sambaDomainName エントリから取得（Samba 環境に依存）
    $res = @ldap_search($ds, $baseDn, '(objectClass=sambaDomain)', ['sambaSID'], 0, 2, 5);
    if ($res && ldap_count_entries($ds, $res) > 0) {
        $e = ldap_first_entry($ds, $res);
        $vals = ldap_get_values($ds, $e, 'sambaSID');
        if ($vals && $vals['count'] > 0) {
            return $vals[0];
        }
    }
    return null;
}

/**
 * 表示整形
 */
function pad(string $s, int $w): string {
    return mb_strimwidth($s, 0, $w, '', 'UTF-8') . str_repeat(' ', max(0, $w - mb_strwidth($s, 'UTF-8')));
}

/**
 * レベル suffix 表示（2桁/2桁空白など）
 */
function levelSuffix(int $level): string {
    $s = (string)$level;
    return str_pad($s, 2, ' ', STR_PAD_LEFT);
}

/**
 * level_id から グループ名（adm-cls 等）を推定
 */
function groupNameFromLevel(int $level): string {
    $def = GroupDef::classify($level); // ['name'=>'adm-cls', 'min'=>1, 'max'=>2, ...]
    return is_array($def) && isset($def['name']) ? $def['name'] : 'err-cls';
}

/**
 * DB1行→LDAP mods を構築
 *  - 既存の employeeType を「空で上書きしない（消さない）」安全実装
 *  - employeeType は：DBの値→level_id 推定→（保険）所属グループ で補完
 */
function buildUserMods(array $row, bool $exists, string $peopleOu, $ds, string $baseDn, bool $verbose): array {
    $mods = [];

    $uid      = (string)$row['login_id'];
    $cmpId    = (int)$row['cmp_id'];
    $userId   = (int)$row['user_id'];
    $levelId  = (int)$row['level_id'];

    // uidNumber/gidNumber のルール（あなたの既定に合わせる）
    $uidNumber = $cmpId * 10000 + $userId;
    // 例: 会社ごとの gid ベース（要件に合わせて調整）
    $gidNumber = 2000 + $cmpId;

    $home = sprintf("/home/%02d-%03d-%s", $cmpId, $userId, $uid);
    $shell = '/bin/bash';

    // displayName / cn / sn / givenName
    $display = (string)($row['display_name'] ?? '');
    $sn      = (string)($row['last_name'] ?? '');
    $given   = (string)($row['first_name'] ?? '');
    if ($display === '' && ($sn !== '' || $given !== '')) {
        $display = trim($sn . ' ' . $given);
    }
    if ($sn === '')    $sn = $uid;  // 最低限
    if ($given === '') $given = $uid;

    // 既存値をざっくり取得（employeeType の保護に使う）
    $dn = "uid={$uid},{$peopleOu}";
    $existingEmpType = null;
    if ($exists) {
        $res = @ldap_read($ds, $dn, '(objectClass=*)', ['employeeType'], 0, 1, 3);
        if ($res && ldap_count_entries($ds, $res) > 0) {
            $e = ldap_first_entry($ds, $res);
            $vals = ldap_get_values($ds, $e, 'employeeType');
            if ($vals && $vals['count'] > 0) $existingEmpType = (string)$vals[0];
        }
    }

    // employeeType を決定
    $empType = normalize_emp_type_from_db_row($row);
    if (!$empType) {
        // level_id から推定
        $def = GroupDef::classify($levelId);
        if (is_array($def) && isset($def['name'])) {
            $empType = sprintf('%s %d', strtolower($def['name']), $levelId);
        }
    }
    if (!$empType && $exists) {
        // 保険: 既存の所属グループから推定
        $empType = fallback_emp_type_from_groups($ds, "ou=Groups,{$baseDn}", $uid);
    }

    // ここ重要：空なら触らない（既存を消さない）
    if (!$empType && $existingEmpType) {
        // 触らない
    } elseif ($empType) {
        $mods[] = ['attrib'=>'employeeType', 'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$empType]];
        if ($verbose) echo "[DBG]  employeeType => {$empType} (uid={$uid})\n";
    }

    // 基本属性（空上書きはしない）
    $mods[] = ['attrib'=>'objectClass',  'modtype'=>LDAP_MOD_ADD, 'values'=>['inetOrgPerson','posixAccount','shadowAccount']];
    $mods[] = ['attrib'=>'uid',          'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$uid]];
    $mods[] = ['attrib'=>'cn',           'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$display ?: $uid]];
    $mods[] = ['attrib'=>'displayName',  'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$display ?: $uid]];
    $mods[] = ['attrib'=>'sn',           'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$sn]];
    $mods[] = ['attrib'=>'givenName',    'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$given]];
    $mods[] = ['attrib'=>'uidNumber',    'modtype'=>LDAP_MOD_REPLACE, 'values'=>[(string)$uidNumber]];
    $mods[] = ['attrib'=>'gidNumber',    'modtype'=>LDAP_MOD_REPLACE, 'values'=>[(string)$gidNumber]];
    $mods[] = ['attrib'=>'homeDirectory','modtype'=>LDAP_MOD_REPLACE, 'values'=>[$home]];
    $mods[] = ['attrib'=>'loginShell',   'modtype'=>LDAP_MOD_REPLACE, 'values'=>[$shell]];

    // パスワード（ここでは例、あなたの実装に差し替え）
    // $mods[] = ['attrib'=>'userPassword','modtype'=>LDAP_MOD_REPLACE,'values'=>['{SSHA}xxxx']];
    // Samba 系は別ツールで同期してもよい

    // php-ldap の ldap_modify_batch() 形式に合わせてキー名変換
    foreach ($mods as &$m) {
        if (isset($m['attr']) && !isset($m['attrib'])) {
            $m['attrib'] = $m['attr'];
            unset($m['attr']);
        }
    }
    unset($m);

    return $mods;
}

/**
 * DB行から employeeType を拾い、正規化
 */
function normalize_emp_type_from_db_row(array $row): ?string {
    foreach (['employee_type','employeeType','emp_type'] as $k) {
        if (!empty($row[$k]) && is_string($row[$k])) {
            $n = normalize_emp_type_string($row[$k]);
            if ($n) return $n;
        }
    }
    return null;
}

/**
 * "ADM-CLS  1" / "adm-cls1" / "adm_cls 02" などを "adm-cls 1" に正規化
 */
function normalize_emp_type_string(string $raw): ?string {
    $s = strtolower(trim($raw));
    $s = preg_replace('/\s+/', ' ', $s);
    $s = str_replace('_','-',$s);
    if (preg_match('/^(adm|dir|mgr|mgs|stf|ent|tmp|err)-cls\s*([0-9]+)$/', $s, $m)) {
        return sprintf('%s-cls %d', $m[1], (int)$m[2]);
    }
    // サフィックス無しは採用しない（範囲抽出が壊れるため）
    return null;
}

/**
 * 所属グループから employeeType を推定（保険）
 * 優先順位は上位→下位へ
 */
function fallback_emp_type_from_groups($ds, string $groupsDn, string $uid): ?string {
    $prio = [
        'adm-cls' => 1,
        'dir-cls' => 3,
        'mgr-cls' => 5,
        'mgs-cls' => 6,
        'stf-cls' => 15,
        'ent-cls' => 20,
        'tmp-cls' => 90,
        'err-cls' => 99,
    ];
    $filter = sprintf('(&(objectClass=posixGroup)(memberUid=%s))', ldap_escape($uid,'',LDAP_ESCAPE_FILTER));
    $res = @ldap_search($ds, $groupsDn, $filter, ['cn'], 0, 50, 10);
    if (!$res || ldap_count_entries($ds, $res) === 0) return null;

    $groups = [];
    for ($e = ldap_first_entry($ds, $res); $e; $e = ldap_next_entry($ds, $e)) {
        $vals = ldap_get_values($ds, $e, 'cn');
        if ($vals && $vals['count'] > 0) $groups[] = strtolower($vals[0]);
    }
    foreach ($prio as $name => $num) {
        if (in_array($name, $groups, true)) {
            return sprintf('%s %d', $name, $num);
        }
    }
    return null;
}

/**
 * modify_batch の mods から、add 用属性配列を作る（最低限）
 */
function modsToAddAttrs(array $mods, array $row): array {
    $attrs = [];
    foreach ($mods as $m) {
        $k = $m['attrib'];
        $v = $m['values'] ?? [];
        if (!isset($attrs[$k])) $attrs[$k] = [];
        foreach ($v as $val) $attrs[$k][] = $val;
    }
    // objectClass が無いと add 失敗するので補完
    if (empty($attrs['objectClass'])) {
        $attrs['objectClass'] = ['inetOrgPerson','posixAccount','shadowAccount'];
    }
    // sn/cn/displayName 最低限
    if (empty($attrs['cn']))          $attrs['cn'] = [$row['login_id']];
    if (empty($attrs['displayName'])) $attrs['displayName'] = [$row['login_id']];
    if (empty($attrs['sn']))          $attrs['sn'] = [$row['login_id']];
    if (empty($attrs['uid']))         $attrs['uid'] = [$row['login_id']];
    return $attrs;
}

/**
 * mods の要約文字列
 */
function summariseMods(array $mods): string {
    $pairs = [];
    foreach ($mods as $m) {
        $a = $m['attrib'];
        $pairs[] = $a . (isset($m['values'][0]) ? " [".substr((string)$m['values'][0],0,8)."…]" : '');
    }
    return '[' . implode(', ', $pairs) . ']';
}


