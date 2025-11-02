#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * sync_mail_extension_from_ldap.php
 *   -P / --P / --people : LDAP ou=People から同期
 *   -U / --U / --users  : LDAP ou=Users から同期
 *   -O / --O / --onamae : PostgreSQL public.passwd_mail から同期（flag_id=1）
 *
 * 既定の更新先: public."情報個人"."電子メールアドレス自社サーバー"
 * --confirm が無い場合は DRY-RUN（更新SQLは表示のみ）
 */

require_once __DIR__ . '/autoload.php';

use Tools\Lib\Env;
use Tools\Lib\Config;
use Tools\Lib\CliColor as C;
use Tools\Lib\LdapConnector;


//==============================
// 定数
//==============================
const TBL_JINJI = 'public."情報個人"';
const COL_MAIL  = '"電子メールアドレス自社サーバー"';
const CONF_PATH = __DIR__ . '/tools.conf';

//==============================
// 互換: tools.conf ローダ
//==============================
/**
 * tools.conf を読み込む。
 * - Tools\Lib\Config::loadIni() が存在すればそれを優先
 * - 無ければ parse_ini_file(true) で代替
 */
function load_tools_conf(string $path): array {
    // 1) 既存ライブラリにメソッドがあればそれを使う
    if (class_exists('Tools\\Lib\\Config') && method_exists('Tools\\Lib\\Config', 'loadIni')) {
        /** @phpstan-ignore-next-line */
        return Tools\Lib\Config::loadIni($path);
    }
    // 2) 自前フォールバック
    if (!is_file($path)) {
        // 最低限のデフォルトを返す
        return [
            'ldap' => [
                'uri'     => 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi',
                'base_dn' => 'dc=e-smile,dc=ne,dc=jp',
                'bind_dn' => '',
                'bind_pass' => '',
            ],
            'postgresql' => [
                'pg_host' => '127.0.0.1',
                'pg_port' => 5432,
                'pg_user' => 'postgres',
                'pg_db'   => 'accounting',
            ],
            'log' => [
                'verbose' => false,
                'confirm' => false,
            ],
        ];
    }
    $ini = parse_ini_file($path, true, INI_SCANNER_TYPED);
    if ($ini === false) {
        throw new RuntimeException("cannot parse ini: {$path}");
    }
    return $ini;
}

//==============================
// CLI parsing（大小区別なし）
//==============================
$argvLower = array_map(fn($a)=>strtolower($a), $argv ?? []);
$HAS = fn(string $flag) => in_array(strtolower($flag), $argvLower, true);

$MODE_P = $HAS('--p') || $HAS('-p') || $HAS('--people');
$MODE_U = $HAS('--u') || $HAS('-u') || $HAS('--users');
$MODE_O = $HAS('--o') || $HAS('-o') || $HAS('--onamae');

$HELP    = $HAS('--help') || $HAS('-h');
$CONFIRM = $HAS('--confirm');
$VERBOSE = $HAS('--verbose') || $HAS('-v');

if ($HELP || (!$MODE_P && !$MODE_U && !$MODE_O)) {
    $me   = basename(__FILE__);
    $dest = TBL_JINJI . '.' . COL_MAIL;
    echo <<<HELP
{$me} - sync mail values into {$dest}

Usage:
  {$me} --P [--confirm] [--verbose]    # LDAP: ou=People
  {$me} --U [--confirm] [--verbose]    # LDAP: ou=Users
  {$me} --O [--confirm] [--verbose]    # PostgreSQL: public.passwd_mail（flag_id=1）
  {$me} --help

Common:
  --confirm  実書き込み（無い場合はDRY-RUN）
  --verbose  追加ログ

Notes for --O:
  - 対象は public.passwd_mail WHERE flag_id=1
  - domain01..05 を 01→05 の順でチェック。最初に 1 の列のドメインを採用
  - 複数 1 がある場合：最初以外の候補メールは “未登録候補” として出力

HELP;
    exit($HELP ? 0 : 1);
}

//==============================
// 設定ロード
//==============================
try {
    $cfg = load_tools_conf(CONF_PATH);
} catch (Throwable $e) {
    fwrite(STDERR, C::red("設定読込エラー: ".$e->getMessage()).PHP_EOL);
    exit(2);
}

$pgHost = (string)($cfg['postgresql']['pg_host'] ?? '127.0.0.1');
$pgPort = (int)($cfg['postgresql']['pg_port'] ?? 5432);
$pgUser = (string)($cfg['postgresql']['pg_user'] ?? 'postgres');
$pgDb   = (string)($cfg['postgresql']['pg_db']   ?? 'accounting');
$pgPass = Env::str('PGPASSWORD', null); // 必要なら export PGPASSWORD=... で

$dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
try {
    $pdo = new PDO($dsn, $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, C::red("DB接続エラー: ".$e->getMessage()).PHP_EOL);
    exit(2);
}
if ($VERBOSE) {
    echo C::cyan("[INFO] DB connected: {$pgHost}:{$pgPort} db={$pgDb} user={$pgUser}").PHP_EOL;
}

// ==============================
// 書込列とUPSERT準備
// ==============================

// モード別に列名を固定
$targetColumn =
    ($MODE_P ? '電子メールアドレスLDAP登録' :
    ($MODE_U ? '電子メールアドレス自社サーバー' :
    ($MODE_O ? '電子メールアドレスお名前ドットコム' :
    'No!'))); // fallback


if( $targetColumn === '' ) {
	echo "カラムエラー";
	exit;
}


// UPSERTクエリ作成
$sql = sprintf(<<<SQL
INSERT INTO public."情報個人メール拡張" (
    cmp_id, user_id, "%s", modified, modified_by
) VALUES (:cmp_id, :user_id, :mail, now(), :by)
ON CONFLICT (cmp_id, user_id)
DO UPDATE SET
    "%s" = EXCLUDED."%s",
    modified = now(),
    modified_by = EXCLUDED.modified_by
SQL, $targetColumn, $targetColumn, $targetColumn);

$stm = $pdo->prepare($sql);

// ==============================
// 書込関数
// ==============================
$total = 0;
$updated = 0;
$skipped = 0;

$applyMail = function(int $cmpId, int $userId, string $email, string $by = 'sync_mail_extension') use ($stm, $CONFIRM, &$updated, &$skipped): void {

    $key = "{$cmpId}-{$userId}";

	echo $key;
	exit;


    if ($CONFIRM) {
        try {
            $stm->execute([
                ':cmp_id'  => $cmpId,
                ':user_id' => $userId,
                ':mail'    => $email,
                ':by'      => $by,
            ]);
            echo C::green("[UPSERT] {$key} -> {$email}").PHP_EOL;
            $updated++;
        } catch (Throwable $e) {
            echo C::red("[ERROR]  {$key} -> {$email} : ".$e->getMessage()).PHP_EOL;
            $skipped++;
        }
    } else {
        echo C::cyan("[DRY]    {$key} -> {$email}").PHP_EOL;
        $skipped++;
    }
};



//==============================
// 共通ヘルパ
//==============================
/*
$total = 0;
$updated = 0;
$skipped = 0;

$applyMail = function(int $cmpId, int $userId, string $email) use ($stUpdate, $CONFIRM, &$updated, &$skipped): void {
    $key = "{$cmpId}-{$userId}";
    if ($CONFIRM) {
        try {
            $stUpdate->execute([':mail'=>$email, ':cmp'=>$cmpId, ':uid'=>$userId]);
            $rowCount = $stUpdate->rowCount();
            if ($rowCount > 0) {
                echo C::green("[UPDATE] {$key} -> {$email}").PHP_EOL;
                $updated++;
            } else {
                echo C::yellow("[WARN]   対象行なし {$key} -> {$email}").PHP_EOL;
                $skipped++;
            }
        } catch (Throwable $e) {
            echo C::red("[ERROR]  {$key} -> {$email} : ".$e->getMessage()).PHP_EOL;
            $skipped++;
        }
    } else {
        echo C::cyan("[DRY]    {$key} -> {$email}").PHP_EOL;
        $skipped++;
    }
};
*/

//==============================
// --O: passwd_mail 由来
//==============================
if ($MODE_O) {
    echo C::boldBlue("=== MODE: Onamae (PostgreSQL public.passwd_mail) ===").PHP_EOL;

    $domainMap = [
        'domain01' => 'esmile-hd.jp',
        'domain02' => 'web-esmile.biz',
        'domain03' => 'e-smile.jp.net',
        'domain04' => 'sol-tribehd.com',
        'domain05' => 'web-esmile.biz',
    ];
    $domainOrder = array_keys($domainMap);

    $sql = <<<SQL
SELECT
  cmp_id, user_id, flag_id, login_id,
  domain01, domain02, domain03, domain04, domain05
FROM public.passwd_mail
WHERE flag_id = 1
ORDER BY cmp_id, user_id
SQL;

    $extrasOut = [];
    $rows = $pdo->query($sql)->fetchAll();

    foreach ($rows as $r) {
        $total++;
        $cmp  = (int)$r['cmp_id'];
        $uid  = (int)$r['user_id'];
        $acct = trim((string)($r['login_id'] ?? ''));

        if ($acct === '') {
            echo C::yellow("[SKIP] login_id が空: {$cmp}-{$uid}").PHP_EOL;
            $skipped++;
            continue;
        }

        $mainEmail = null;
        $extraCandidates = [];

        foreach ($domainOrder as $key) {
            $flag = (int)($r[$key] ?? 0);
            if ($flag === 1) {
                $email = "{$acct}@{$domainMap[$key]}";
                if ($mainEmail === null) {
                    $mainEmail = $email;
                } else {
                    $extraCandidates[] = $email;
                }
            }
        }

        if ($mainEmail === null) {
            echo C::yellow("[SKIP] ドメイン未選択: {$cmp}-{$uid} (login_id={$acct})").PHP_EOL;
            $skipped++;
            continue;
        }

        $applyMail($cmp, $uid, $mainEmail);

        if (!empty($extraCandidates)) {
            $extrasOut[] = [
                'cmp_id'=>$cmp, 'user_id'=>$uid,
                'login_id'=>$acct,
                'main'=>$mainEmail,
                'candidates'=>$extraCandidates,
            ];
        }
    }

    if (!empty($extrasOut)) {
        echo PHP_EOL.C::boldBlue("=== 未登録候補（同時に 1 の列が複数） ===").PHP_EOL;
        foreach ($extrasOut as $x) {
            $key = "{$x['cmp_id']}-{$x['user_id']} ({$x['login_id']})";
            echo "- {$key}".PHP_EOL;
            echo "  main:   {$x['main']}".PHP_EOL;
            echo "  others: ".implode(', ', $x['candidates']).PHP_EOL;
        }
    }
}

//==============================
// --P / --U: LDAP 由来
//==============================
if ($MODE_P || $MODE_U) {
    $baseDnRoot = (string)($cfg['ldap']['base_dn'] ?? 'dc=e-smile,dc=ne,dc=jp');
    $baseOU     = $MODE_P ? 'ou=People' : 'ou=Users';
    $baseDn     = "{$baseOU},{$baseDnRoot}";

    echo C::boldBlue("=== MODE: LDAP ({$baseOU}) ===").PHP_EOL;

    // LdapConnector は P/U の時のみ必要
    if (!class_exists('Tools\\Lib\\LdapConnector')) {
        echo C::red("[ERROR] LdapConnector が見つかりません。autoload.php / ライブラリ配置を確認してください。").PHP_EOL;
        exit(3);
    }

    /** @var Tools\Lib\LdapConnector $ldap */

	echo $cfg['ldap'];
	exit;


    $ldap = new Tools\Lib\LdapConnector($cfg['ldap']);
    $ldap->bind();

    $filter = '(mail=*)';
    $attrs  = ['uidNumber','mail'];
    $entries = $ldap->search($baseDn, $filter, $attrs);

    foreach ($entries as $e) {
        $total++;
        $uidNumber = (int)($e['uidNumber'][0] ?? 0);
        $mail      = (string)($e['mail'][0] ?? '');

        if ($uidNumber <= 0 || $mail === '') {
            $skipped++;
            continue;
        }
        // uidNumber: 上位=cmp_id、下位4桁=user_id
        $uidStr = (string)$uidNumber;
        $user4  = (int)substr($uidStr, -4);
        $cmp    = (int)substr($uidStr, 0, -4);
        if ($cmp <= 0 || $user4 <= 0) {
            echo C::yellow("[SKIP] uidNumber不正: {$uidNumber}").PHP_EOL;
            $skipped++;
            continue;
        }

        $applyMail($cmp, $user4, $mail);
    }
}

//==============================
// Summary
//==============================
echo PHP_EOL.C::boldBlue("=== SUMMARY ===").PHP_EOL;
echo "  total:   {$total}".PHP_EOL;
echo "  updated: {$updated}".PHP_EOL;
echo "  skipped: {$skipped}".PHP_EOL;
if (!$CONFIRM) {
    echo C::cyan("※ DRY-RUN（--confirm で実書き込み）").PHP_EOL;
}


