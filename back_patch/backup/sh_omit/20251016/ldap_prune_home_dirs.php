#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_prune_home_dirs.php (refactored with passwd-protect)
 *
 * LDAP の homeDirectory 一覧と /home 以下を突合し、LDAPに存在しないホームディレクトリを検出・削除。
 * 既定で /etc/passwd のユーザー名/ホームは保護します（--no-protect-passwd で無効化）。
 */

require_once __DIR__ . '/autoload.php';

// ★Lib Ldap 共通ライブラリ
use Tools\Lib\CliColor;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;

/* ============================= ログ ============================= */
$B = class_exists(CliColor::class) ? [CliColor::class,'bold']    : fn(string $s)=>$s;
$G = class_exists(CliColor::class) ? [CliColor::class,'green']   : fn(string $s)=>$s;
$Y = class_exists(CliColor::class) ? [CliColor::class,'yellow']  : fn(string $s)=>$s;
$R = class_exists(CliColor::class) ? [CliColor::class,'red']     : fn(string $s)=>$s;
$C = class_exists(CliColor::class) ? [CliColor::class,'cyan']    : fn(string $s)=>$s;

$log = fn(string $m) => fwrite(STDOUT, $m.(str_ends_with($m,"\n") ? '' : "\n"));
$err = fn(string $m) => fwrite(STDERR, $m.(str_ends_with($m,"\n") ? '' : "\n"));

/* ============================ オプション ============================ */
$opt = getopt('', [
    'help',
    'home-root:',
    'users-dn:',
    'attr-home:',
    'regex:',
    'delete-empty-only',
    'age-days:',
    'protect:',
    'dry-run',
    'confirm',
    'uri:',
    'debug',
    'no-protect-passwd',   // ← 追加: /etc/passwd 保護を無効化
]);

if (isset($opt['help'])) { echoHelp(); exit(0); }

$confirm   = isset($opt['confirm']) && !isset($opt['dry-run']);
$debug     = isset($opt['debug']);
$homeRoot  = rtrim((string)($opt['home-root'] ?? '/home'), '/');
$baseDnEnv = Env::first(['BASE_DN','LDAP_BASE_DN'], 'dc=e-smile,dc=ne,dc=jp');
$usersDn   = (string)($opt['users-dn'] ?? "ou=Users,{$baseDnEnv}");
$attrHome  = (string)($opt['attr-home'] ?? 'homeDirectory');
$regex     = (string)($opt['regex'] ?? '');                  // ディレクトリ名のフィルタ
$emptyOnly = isset($opt['delete-empty-only']);
$ageDays   = isset($opt['age-days']) ? max(0, (int)$opt['age-days']) : 0;
$uriOpt    = $opt['uri'] ?? null;
$protectPasswd = !isset($opt['no-protect-passwd']);          // 既定: true

$uri      = $uriOpt ?? Env::first(['LDAP_URL','LDAP_URI','LDAPURI'], 'ldapi:///');
$fallback = Env::get('FALLBACK_LDAPS_URL', null, $uri);
// $fallback = Env::get('FALLBACK_LDAPS_URL', null, 'ldaps://ovs-012.e-smile.local');


/* ============================ 開始表示 ============================ */
$log($B("=== prune-home START ==="));
$log(sprintf(
    "HOME_ROOT=%s USERS_DN=%s ATTR=%s REGEX=%s CONFIRM=%s EMPTY_ONLY=%s AGE_DAYS=%d PROTECT_PASSWD=%s",
    $homeRoot,
    $usersDn,
    $attrHome,
    ($regex !== '' ? $regex : '(none)'),
    $confirm ? $G('YES') : $Y('NO'),
    $emptyOnly ? $G('YES') : $Y('NO'),
    $ageDays,
    $protectPasswd ? $G('YES') : $Y('NO')
));

/* ============================ 前提チェック ============================ */
if (!is_dir($homeRoot)) {
    $err($R("[ERROR] home-root not found: {$homeRoot}"));
    exit(2);
}

/* ============================ LDAP接続 ============================ */
try { Connection::init($uri); } catch (\Throwable $e) { /* noop */ }

try {
    try {
        $ds = Connection::connect($uri);
        Connection::bind($ds, null, null, $uri);  // ldapiはNOP, 非ldapiはSimple Bind
    } catch (\Throwable $e) {
        $log($Y("[INFO] primary connect failed; fallback to {$fallback}"));
        $ds = Connection::connect($fallback);
        Connection::bind($ds, null, null, $fallback);
    }
} catch (\Throwable $e) {
    $err($R("[ERROR] LDAP connect/bind failed: ".$e->getMessage()));
    if ($debug) $err($e->getTraceAsString());
    exit(70);
}

/* ============================ LDAP: 有効home集合 ============================ */
try {
    $valid = fetchValidHomeDirs($ds, $usersDn, $attrHome);
} catch (\Throwable $e) {
    $err($R("[ERROR] LDAP fetch failed: ".$e->getMessage()));
    if ($debug) $err($e->getTraceAsString());
    Connection::close($ds);
    exit(70);
}

/* ============================ /etc/passwd 保護集合 ============================ */
$passwdUsers = [];  // ユーザー名 => true
$passwdHomes = [];  // 絶対パス   => true
if ($protectPasswd) {
    [$passwdUsers, $passwdHomes] = loadPasswdGuards('/etc/passwd');
}

/* ============================ FS走査 ============================ */
$protectNames = [];
if (!empty($opt['protect'])) {
    foreach (explode(',', (string)$opt['protect']) as $x) {
        $x = trim($x);
        if ($x !== '') $protectNames[$x] = true;
    }
}

$dh = @opendir($homeRoot);
if ($dh === false) {
    $err($R("[ERROR] opendir failed: {$homeRoot}"));
    Connection::close($ds);
    exit(70);
}

$targets = [];
while (($entry = readdir($dh)) !== false) {
    if ($entry === '.' || $entry === '..') continue;
    $full = "{$homeRoot}/{$entry}";
    if (!is_dir($full)) continue;

    // 1) 明示的な protect
    if (isset($protectNames[$entry])) {
        $log($C("[INFO] protect(name): {$entry}"));
        continue;
    }
    // 2) /etc/passwd のユーザー名と一致 or /etc/passwd のホームと一致
    if ($protectPasswd && (isset($passwdUsers[$entry]) || isset($passwdHomes[$full]))) {
        $log($C("[INFO] protect(passwd): {$entry}"));
        continue;
    }
    // 3) 正規表現フィルタ（ディレクトリ名）
    if ($regex !== '') {
        if (@preg_match("~{$regex}~u", $entry) !== 1) {
            continue;
        }
    }
    // 4) LDAPで参照されているか？
    if (isset($valid[$full])) {
        continue; // 存在するhome
    }
    // 5) 更新時間フィルタ
    if ($ageDays > 0) {
        $mtime = @filemtime($full);
        if ($mtime !== false) {
            $days = (time() - $mtime) / 86400;
            if ($days < $ageDays) {
                $log($C(sprintf("[INFO] skip by age: %s (last: %.1fd)", $entry, $days)));
                continue;
            }
        }
    }
    // 6) 空のみ
    if ($emptyOnly && !isDirEmpty($full)) {
        $log($C("[INFO] skip non-empty: {$entry}"));
        continue;
    }

    $targets[] = $full;
}
closedir($dh);

/* ============================ 実行フェーズ ============================ */
$ok=0; $skipped=0; $ng=0;
foreach ($targets as $dir) {
    if (!$confirm) {
        $log($Y("[DRY] delete: {$dir}"));
        $skipped++;
        continue;
    }
    if (!rrmdir($dir)) {
        $ng++; $err($R("[ERROR] delete failed: {$dir}"));
        continue;
    }
    $ok++; $log($G("[OK  ] deleted: {$dir}"));
}

/* ============================ 終了表示 ============================ */
$log($B(sprintf("SUMMARY: deleted=%d dryrun=%d failed=%d candidates=%d",
    $ok, $skipped, $ng, count($targets))));
$log($B("=== prune-home DONE ==="));

Connection::close($ds);
exit($ng > 0 ? 1 : 0);

/* ============================ 関数群 ============================ */

function echoHelp(): void {
    $base = Env::first(['BASE_DN','LDAP_BASE_DN'], 'dc=e-smile,dc=ne,dc=jp');
    $self = $GLOBALS['argv'][0] ?? 'ldap_prune_home_dirs.php';
    echo <<<HELP
{$self} - LDAPに存在しないホームディレクトリの整理ツール

[接続ポリシー]
  - ldapi:// は SASL/EXTERNAL（Connection側）
  - ldap:// は StartTLS を強制（Connection側）
  - ldaps:// はそのまま Bind（Connection側）
  - 1次接続失敗時は FALLBACK_LDAPS_URL にフォールバック

[環境変数]
  BASE_DN="{$base}"
  LDAP_URL / LDAP_URI / LDAPURI
  BIND_DN="cn=Admin,{$base}"               # 非ldapi時
  BIND_PW="(PW)"                            # 非ldapi時
  FALLBACK_LDAPS_URL="ldaps://ovs-012.e-smile.local"

[主なオプション]
  --home-root "<path>"      既定: /home
  --users-dn "<DN>"         既定: "ou=Users,{$base}"
  --attr-home "<attr>"      既定: homeDirectory
  --regex "<pattern>"       ディレクトリ名フィルタ（例: '^\\d{2}-\\d{3}-'）
  --delete-empty-only       空ディレクトリのみ削除
  --age-days N              N日以上更新の無いもののみ対象
  --protect "a,b,c"         除外（basename一致）
  --dry-run                 削除せず表示（既定）
  --confirm                 実際に削除
  --uri "<ldap-uri>"        明示的に接続URIを指定（未指定は環境）
  --no-protect-passwd       /etc/passwd のユーザー保護を無効化（既定は保護ON）

[例]
  php {$self} --home-root /home --users-dn "ou=Users,{$base}" \\
      --regex '^\\d{2}-\\d{3}-' --delete-empty-only --age-days 7 \\
      --protect "lost+found" --confirm

HELP;
}

/** LDAPから有効ホームの集合を作る（絶対パスでキー化） */
function fetchValidHomeDirs(\LDAP\Connection $ds, string $usersDn, string $attrHome): array {
    $res = [];
    $attrKey = strtolower($attrHome);
    $attrs = [$attrKey];
    $sr = @ldap_search($ds, $usersDn, '(objectClass=posixAccount)', $attrs);
    if ($sr === false) {
        throw new \RuntimeException('LDAP search failed: '.ldap_error($ds));
    }
    $entries = ldap_get_entries($ds, $sr);
    if (!isset($entries['count'])) return $res;

    for ($i=0; $i < $entries['count']; $i++) {
        $e = $entries[$i];
        // ldap_get_entries の属性キーは小文字に正規化される
        if (!isset($e[$attrKey][0])) continue;
        $dir = rtrim((string)$e[$attrKey][0], '/');
        if ($dir !== '') $res[$dir] = true;
    }
    return $res;
}

/** /etc/passwd を読んでユーザー名とホームを保護集合に投入 */
function loadPasswdGuards(string $passwdFile): array {
    $users = []; // username => true
    $homes = []; // /home/foo => true (フルパス)
    $lines = @file($passwdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [$users, $homes];

    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode(':', $line);
        if (count($parts) < 7) continue;
        [$name,, $uid,,,$home,] = $parts;

        // UID フィルタは掛けない（システム/サービスユーザーも守る）
        $name = trim($name);
        $home = rtrim(trim($home), '/');

        if ($name !== '') $users[$name] = true;
        if ($home !== '') $homes[$home] = true;
    }
    return [$users, $homes];
}

/** 空かどうか */
function isDirEmpty(string $dir): bool {
    $h = @opendir($dir);
    if ($h === false) return false;
    $empty = true;
    while (($e = readdir($h)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $empty = false; break;
    }
    closedir($h);
    return $empty;
}

/** 再帰削除（ディレクトリ/ファイル両対応） */
function rrmdir(string $path): bool {
    if (!is_dir($path) || is_link($path)) {
        return @unlink($path);
    }
    $items = @scandir($path);
    if ($items === false) return false;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $full = $path . DIRECTORY_SEPARATOR . $it;
        if (is_dir($full) && !is_link($full)) {
            if (!rrmdir($full)) return false;
        } else {
            if (!@unlink($full)) return false;
        }
    }
    return @rmdir($path);
}

