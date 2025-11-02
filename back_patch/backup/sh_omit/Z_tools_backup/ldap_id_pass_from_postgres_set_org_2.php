#!/usr/bin/env php
<?php
/**
 * ldap_id_pass_from_postgres_set.php
 *
 * DB（passwd_tnas × 情報個人）から現職者のレコードを取得し、
 * kakasi で生成した uid を用いて /home/%02d-%03d-%s のホームを必要に応じて作成。
 *
 * 作成しない条件:
 *  - /etc/passwd に同名ローカル（UID>=1000 & /home/...）がある
 *  - invalid >= 1（=1,2）
 *  - すでに /home/%02d-%03d-%s が存在
 *
 * 既定は DRY-RUN。--confirm で実行。
 * 実行ホストは ovs-010 / ovs-012 に限定。
 * DB接続は環境変数（.pgpass対応）:
 *   PGHOST=127.0.0.1 / PGPORT=5432 / PGDATABASE=accounting / PGUSER=postgres / PGPASSWORD(任意)
 *
 * 必要に応じて --sql で上書き可能（既定SQLは JOIN 済みで必要カラム取得）。
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

// ===== 実行ホスト制限 =====
$ALLOWED_HOSTS = ['ovs-010','ovs-012'];
$hostname = gethostname() ?: php_uname('n');
$shortHost = strtolower(preg_replace('/\..*$/', '', $hostname));
if (!in_array($shortHost, $ALLOWED_HOSTS, true)) {
    fwrite(STDERR, "[ERROR] This script is allowed only on ovs-010 / ovs-012. (current: {$hostname})\n");
    exit(1);
}

// ===== オプション =====
$options = getopt('', [
    'sql::',           // 取得SQL（cmp_id, user_id, 姓かな, 名かな, ミドルネーム, invalid, samba_id推奨）を返す
    'home-root::',     // 既定: /home
    'skel::',          // 既定: /etc/skel
    'mode::',          // 既定: 0750
    'confirm',         // 付けたら実行
    'log::',           // ログファイル（任意）
    'min-local-uid::', // 既定: 1000
]);

// 既定SQL（現職のみ：invalid<2）
$DEFAULT_SQL = 'SELECT 
  t.cmp_id AS cmp_id,
  t.user_id AS user_id,
  t.samba_id AS samba_id,
  COALESCE(p.invalid,0) AS invalid,
  p."姓かな" AS family_kana,
  p."名かな" AS given_kana,
  COALESCE(p."ミドルネーム", \'\') AS middle_name
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> \'\'';

$SQL       = $options['sql']        ?? $DEFAULT_SQL;
$HOME_ROOT = rtrim($options['home-root'] ?? '/home', '/');
$SKEL_DIR  = rtrim($options['skel']      ?? '/etc/skel', '/');
$MODE_STR  = $options['mode']            ?? '0750';
$MODE      = intval($MODE_STR, 8);
$CONFIRM   = isset($options['confirm']);
$LOGFILE   = $options['log'] ?? null;
$MIN_LOCAL = isset($options['min-local-uid']) ? (int)$options['min-local-uid'] : 1000;

// ===== 出力ヘルパ =====
function log_line(?string $file, string $msg) {
    echo $msg, PHP_EOL;
    if ($file) @file_put_contents($file, '['.date('Y-m-d H:i:s')."] ".$msg.PHP_EOL, FILE_APPEND);
}
function abortx(string $msg, ?string $file = null, int $code = 1) {
    log_line($file, "[ERROR] ".$msg);
    exit($code);
}

// ===== DB接続（環境変数デフォ）=====
function get_pg_pdo(): PDO {
    $pgHost = getenv('PGHOST')     ?: '127.0.0.1';
    $pgPort = getenv('PGPORT')     ?: '5432';
    $pgDb   = getenv('PGDATABASE') ?: 'accounting';
    $pgUser = getenv('PGUSER')     ?: 'postgres';
    $pgPass = getenv('PGPASSWORD'); // null/未設定なら .pgpass を利用可
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $pgHost, $pgPort, $pgDb);
    $opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    return new PDO($dsn, $pgUser, $pgPass ?: null, $opt);
}

// ===== kakasi: かな → ローマ字（英小文字、空白と'除去）=====
function kanaToRomaji($kana) {
    $kana = escapeshellarg($kana);
    $romaji = shell_exec("echo $kana | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
    $romaji = strtolower(str_replace([" ", "'"], '', trim($romaji)));
    return $romaji;
}

// ===== /etc/passwd ローカル（UID>=min & /home/...）=====
function load_local_passwd_users(string $homeRoot = '/home', int $minUid = 1000): array {
    $keep = [];
    $homePrefix = rtrim($homeRoot, '/').'/';
    $fh = @fopen('/etc/passwd', 'r');
    if (!$fh) return $keep;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $p = explode(':', $line);
        if (count($p) < 7) continue;
        $name = $p[0];
        $uid  = (int)$p[2];
        $home = $p[5];
        if ($uid >= $minUid && strpos($home, $homePrefix) === 0) $keep[$name] = true;
    }
    fclose($fh);
    return $keep; // [username => true]
}

// ===== 事前チェック =====
if (!is_dir($HOME_ROOT)) abortx("home-root が存在しません: {$HOME_ROOT}", $LOGFILE);
if (!is_dir($SKEL_DIR))  abortx("skel ディレクトリが存在しません: {$SKEL_DIR}", $LOGFILE);

log_line($LOGFILE, "=== START add-home ===");
log_line($LOGFILE, "HOST      : {$hostname}");
log_line($LOGFILE, "HOME_ROOT : {$HOME_ROOT}");
log_line($LOGFILE, "SKEL      : {$SKEL_DIR}");
log_line($LOGFILE, "MODE      : ".decoct($MODE)." (".$MODE.")");
log_line($LOGFILE, "CONFIRM   : ".($CONFIRM ? 'YES (execute)' : 'NO (dry-run)'));
log_line($LOGFILE, "-----------");

// /etc/passwd のローカル保持テーブル（ユーザー名ベース）
$LOCAL_KEEP = load_local_passwd_users($HOME_ROOT, $MIN_LOCAL);
if ($LOCAL_KEEP) log_line($LOGFILE, "[INFO] /etc/passwd local keep: ".implode(',', array_keys($LOCAL_KEEP)));

// DB取得
try { $pdo = get_pg_pdo(); }
catch (Throwable $e) { abortx("DB接続失敗: ".$e->getMessage(), $LOGFILE); }

try { $rows = $pdo->query($SQL)->fetchAll(); }
catch (Throwable $e) { abortx("SQL実行失敗: ".$e->getMessage(), $LOGFILE); }

if (!$rows) { log_line($LOGFILE, "[INFO] 対象0件。終了。"); exit(0); }

// 1件ずつ
foreach ($rows as $row) {
    $cmp_id      = (int)($row['cmp_id'] ?? 0);
    $user_id     = (int)($row['user_id'] ?? 0);
    $familyKana  = (string)($row['family_kana'] ?? '');
    $givenKana   = (string)($row['given_kana'] ?? '');
    $middleName  = trim((string)($row['middle_name'] ?? ''));
    $invalid     = (int)($row['invalid'] ?? 0);
    $samba_id    = isset($row['samba_id']) ? trim((string)$row['samba_id']) : '';

    if ($cmp_id<=0 || $user_id<=0 || $familyKana==='' || $givenKana==='') {
        log_line($LOGFILE, "[SKIP][BADROW] 必須項目不足 cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }

    // 追加禁止：invalid>=1
    if ($invalid >= 1) {
        log_line($LOGFILE, "[SKIP][INVALID>=1] cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }

    // 追加禁止：/etc/passwd にローカル存在（samba_id がわかる場合の目安）
    if ($samba_id !== '' && isset($LOCAL_KEEP[$samba_id])) {
        log_line($LOGFILE, "[SKIP][LOCAL] /etc/passwdに存在 => 追加しない: {$samba_id}");
        continue;
    }

    // uid と homeDir を生成
    $uid = kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName;
    $homePath = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid);

    if (is_dir($homePath)) {
        log_line($LOGFILE, "[KEEP][EXIST] 既存: {$homePath}");
        continue;
    }

    if (!$CONFIRM) {
        log_line($LOGFILE, "[DRY][MKDIR] {$homePath} (mode ".decoct($MODE).") from {$SKEL_DIR}");
        continue;
    }

    // 作成 & skel コピー
    if (!@mkdir($homePath, $MODE, true)) {
        log_line($LOGFILE, "[ERR ][MKDIR] 失敗: {$homePath}");
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($SKEL_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($SKEL_DIR));
        $dst = $homePath.$rel;
        if ($item->isDir()) {
            @mkdir($dst, $MODE, true);
        } else {
            @copy($item->getPathname(), $dst);
        }
    }

    log_line($LOGFILE, "[ADD ][HOME] {$homePath} (mode=".decoct($MODE).")");
}

log_line($LOGFILE, "=== DONE add-home ===");

