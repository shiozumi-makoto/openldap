#!/usr/bin/env php
<?php
/**
 * prune_home_dirs.php
 *
 * /home 直下のホームを整理：
 *  - [DEL][INVALID2]  … 情報個人.invalid=2（退職）に該当する「想定ホーム」を削除/退避
 *  - [DEL][MISSING_DB]… DBで計算した「現職の想定ホーム集合」に無いホームを削除/退避
 *  - [KEEP][VALID_DB] … DBで計算した「現職の想定ホーム集合」に一致
 *  - [KEEP][LOCAL]    … /etc/passwd のローカルユーザー(UID>=min & /home/xxx)
 *  - [KEEP][EXCLUDE]  … --exclude 指定名
 *  - [KEEP][NEW]      … --age-days より新しい
 *  - [SKIP][BADNAME]  … 不正名（YY-XXX-uid 形式以外は安全のためスキップ）
 *
 * ★ホーム名は追加側と同じ規則：
 *    uid = kakasi(姓かな) + '-' + kakasi(名かな) + ミドルネーム（英小文字、空白と'除去）
 *    homePath = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid)
 *
 * 既定は DRY-RUN。--confirm で実行。--trash 指定で退避。
 * 実行ホスト：ovs-010 / ovs-012 のみ。
 * DB接続：環境変数（.pgpass対応）。
 *
 * ★LDAP削除（任意・安全OFF）:
 *   --ldap-delete を付けたときのみ、フォルダ削除と同時に LDAP アカウントも削除。
 *   削除対象の uid は ホーム名の <uid> 部分（YY-XXX-<uid>）。
 *   LDAP検索: (uid=<uid>) を --ldap-base-dn 配下で検索 → DN を見つけて削除。
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
  'home-root::','exclude::','confirm','trash::','age-days::',
  'min-local-uid::','log::',

  // SQL 上書き（任意）
  'sql-valid::','sql-invalid::',

  // ★LDAP削除
  'ldap-delete','ldap-url::','bind-dn::','bind-pass::','ldap-base-dn::','ldap-attr-uid::',
]);

$HOME_ROOT    = rtrim($options['home-root'] ?? '/home', '/');
$EXCLUDE      = array_filter(array_map('trim', explode(',', $options['exclude'] ?? 'lost+found,skel')));
$CONFIRM      = isset($options['confirm']);
$TRASH_DIR    = $options['trash'] ?? null;
$AGE_DAYS     = isset($options['age-days']) ? (int)$options['age-days'] : null;
$MIN_LOCAL_UID= isset($options['min-local-uid']) ? (int)$options['min-local-uid'] : 1000;
$LOGFILE      = $options['log'] ?? null;

// ===== DB接続（環境変数デフォルト）=====
$envHost = getenv('PGHOST')     ?: '127.0.0.1';
$envPort = getenv('PGPORT')     ?: '5432';
$envDb   = getenv('PGDATABASE') ?: 'accounting';
$envUser = getenv('PGUSER')     ?: 'postgres';
$envPass = getenv('PGPASSWORD'); // nullなら .pgpass 利用

$PG_DSN  = sprintf('pgsql:host=%s;port=%s;dbname=%s', $envHost, $envPort, $envDb);
$PG_USER = $envUser;
$PG_PASS = $envPass ?: null;

// ===== 既定SQL（cmp_id / user_id / かな・ミドル名 から uid を組み立てるために必要な列）=====
$DEFAULT_SQL_VALID = 'SELECT 
  t.cmp_id AS cmp_id,
  t.user_id AS user_id,
  COALESCE(p.invalid,0) AS invalid,
  p."姓かな" AS family_kana,
  p."名かな" AS given_kana,
  COALESCE(p."ミドルネーム", \'\') AS middle_name
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> \'\'
  AND COALESCE(p.invalid,0) < 2';

$DEFAULT_SQL_INVALID = 'SELECT 
  t.cmp_id AS cmp_id,
  t.user_id AS user_id,
  p.invalid AS invalid,
  p."姓かな" AS family_kana,
  p."名かな" AS given_kana,
  COALESCE(p."ミドルネーム", \'\') AS middle_name
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> \'\'
  AND p.invalid = 2';

$SQL_VALID   = $options['sql-valid']   ?? $DEFAULT_SQL_VALID;
$SQL_INVALID = $options['sql-invalid'] ?? $DEFAULT_SQL_INVALID;

// ===== LDAP削除 設定 =====
$LDAP_DELETE   = isset($options['ldap-delete']);
$LDAP_URL      = $options['ldap-url']     ?? 'ldap://127.0.0.1';
$BIND_DN       = $options['bind-dn']      ?? '';
$BIND_PASS     = $options['bind-pass']    ?? '';
$LDAP_BASE_DN  = $options['ldap-base-dn'] ?? '';
$LDAP_ATTR_UID = $options['ldap-attr-uid']?? 'uid';

// ===== ヘルパ =====
function log_line(?string $file, string $msg) {
  echo $msg, PHP_EOL;
  if ($file) @file_put_contents($file, '['.date('Y-m-d H:i:s')."] ".$msg.PHP_EOL, FILE_APPEND);
}
function abortx(string $msg, ?string $file = null, int $code = 1) {
  log_line($file, "[ERROR] ".$msg);
  exit($code);
}
function get_pg_pdo(string $dsn, string $user, ?string $pass): PDO {
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}
// kakasi: かな → ローマ字（英小文字、空白と'除去）
function kanaToRomaji($kana) {
  $kana = escapeshellarg($kana);
  $romaji = shell_exec("echo $kana | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
  $romaji = strtolower(str_replace([' ', "'"], '', trim($romaji)));
  return $romaji;
}
// /etc/passwd ローカル（UID>=min & /home/...）
function loadLocalPasswdUsers(string $passwdFile, int $minUid, string $homeRoot): array {
  $keep = [];
  $homePrefix = rtrim($homeRoot,'/').'/';
  $fh = @fopen($passwdFile, 'r');
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
  return array_keys($keep);
}
// 物理削除
function rrmdir(string $dir): bool {
  if (strlen($dir) < 10) return false; // 安全装置
  if (!is_dir($dir)) return false;
  $it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
  foreach ($it as $item) {
    $p = $item->getPathname();
    if ($item->isDir() && !$item->isLink()) {
      if (!rrmdir($p)) return false;
    } else {
      if (!@unlink($p)) return false;
    }
  }
  return @rmdir($dir);
}

// LDAPユーティリティ
function ldap_connect_bind(string $url, string $bindDn, string $bindPass) {
  $conn = @ldap_connect($url);
  if (!$conn) return [null, 'connect failed'];
  ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
  ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
  if ($bindDn !== '') {
    if (!@ldap_bind($conn, $bindDn, $bindPass)) {
      $err = ldap_error($conn);
      @ldap_unbind($conn);
      return [null, "bind failed: {$err}"];
    }
  } else {
    if (!@ldap_bind($conn)) {
      $err = ldap_error($conn);
      @ldap_unbind($conn);
      return [null, "anonymous bind failed: {$err}"];
    }
  }
  return [$conn, null];
}
function ldap_find_dn_by_uid($conn, string $baseDn, string $attrUid, string $uid) {
  // フィルタエスケープ
  if (!function_exists('ldap_escape')) {
    function ldap_escape($subject, $ignore = '', $flags = 0) {
      $search = ['*', '(', ')', '\\', "\x00"];
      $replace= array_map(fn($c)=>'\\'.str_pad(dechex(ord($c)),2,'0',STR_PAD_LEFT), $search);
      return str_replace($search,$replace,$subject);
    }
  }
  $filter = sprintf('(%s=%s)', $attrUid, ldap_escape($uid, '', LDAP_ESCAPE_FILTER));
  $sr = @ldap_search($conn, $baseDn, $filter, ['dn']);
  if (!$sr) return [null, ldap_error($conn)];
  $entries = @ldap_get_entries($conn, $sr);
  if (!$entries || ($entries['count'] ?? 0) < 1) return [null, null];
  $dn = $entries[0]['dn'] ?? null;
  return [$dn, null];
}

// ===== 前提チェック =====
if (!is_dir($HOME_ROOT)) abortx("home-root が存在しません: {$HOME_ROOT}", $LOGFILE);
if ($TRASH_DIR && !is_dir($TRASH_DIR)) {
  if (!@mkdir($TRASH_DIR, 0700, true)) abortx("trash ディレクトリを作成できません: {$TRASH_DIR}", $LOGFILE);
}
if ($LDAP_DELETE && $LDAP_BASE_DN === '') {
  abortx("LDAP削除を有効化していますが --ldap-base-dn が未指定です。", $LOGFILE);
}

// ===== ローカル保持ユーザー =====
$localUsers = loadLocalPasswdUsers('/etc/passwd', $MIN_LOCAL_UID, $HOME_ROOT);

// ===== DBから「想定ホーム」を構築 =====
try { $pdo = get_pg_pdo($PG_DSN, $PG_USER, $PG_PASS); }
catch (Throwable $e) { abortx("DB接続失敗: ".$e->getMessage(), $LOGFILE); }

// 現職（invalid<2）
$validHomeSet = []; // [fullpath => true]
$validCount = 0;
try {
  foreach ($pdo->query($SQL_VALID) as $row) {
    $cmp_id = (int)$row['cmp_id'];
    $user_id = (int)$row['user_id'];
    $uid = kanaToRomaji((string)$row['family_kana']).'-'.kanaToRomaji((string)$row['given_kana']).trim((string)$row['middle_name']);
    $homePath = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid);
    $validHomeSet[$homePath] = true;
    $validCount++;
  }
} catch (Throwable $e) {
  abortx("SQL(valid) 失敗: ".$e->getMessage(), $LOGFILE);
}

// 退職（invalid=2）
$invalid2HomeSet = []; // [fullpath => true]
$invalid2Count = 0;
try {
  foreach ($pdo->query($SQL_INVALID) as $row) {
    $cmp_id = (int)$row['cmp_id'];
    $user_id = (int)$row['user_id'];
    $uid = kanaToRomaji((string)$row['family_kana']).'-'.kanaToRomaji((string)$row['given_kana']).trim((string)$row['middle_name']);
    $homePath = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid);
    $invalid2HomeSet[$homePath] = true;
    $invalid2Count++;
  }
} catch (Throwable $e) {
  abortx("SQL(invalid) 失敗: ".$e->getMessage(), $LOGFILE);
}

// ===== ヘッダ =====
log_line($LOGFILE, "=== prune-home START ===");
log_line($LOGFILE, "HOSTNAME  : {$hostname}");
log_line($LOGFILE, "HOME_ROOT : {$HOME_ROOT}");
log_line($LOGFILE, "CONFIRM   : ".($CONFIRM ? 'YES (execute)' : 'NO (dry-run)'));
log_line($LOGFILE, "TRASH_DIR : ".($TRASH_DIR ?: '(なし)'));
log_line($LOGFILE, "EXCLUDE   : ".(implode(', ', $EXCLUDE) ?: '(なし)'));
log_line($LOGFILE, "AGE_DAYS  : ".($AGE_DAYS !== null ? $AGE_DAYS : '(制限なし)'));
log_line($LOGFILE, "[INFO] valid homes: {$validCount} / invalid2 homes: {$invalid2Count}");
log_line($LOGFILE, "-----------");

// ===== スキャン =====
$entries = @scandir($HOME_ROOT);
if ($entries === false) abortx("scandir 失敗: {$HOME_ROOT}", $LOGFILE);

$now = time();
$ageLimitTs = ($AGE_DAYS !== null) ? ($now - ($AGE_DAYS * 86400)) : null;

$DEL_invalid2 = [];
$DEL_missing  = [];
$KEEP_local   = [];
$KEEP_valid   = [];
$KEEP_exclude = [];
$KEEP_new     = [];
$SKIP_badname = [];

$targets = [];       // 削除対象ディレクトリのフルパス
$targetsUid = [];    // basename → uid（YY-XXX-<uid> の <uid>）
$targetReason = [];  // basename → INVALID2 or MISSING_DB

foreach ($entries as $name) {
  if ($name === '.' || $name === '..') continue;
  if (in_array($name, $EXCLUDE, true)) { $KEEP_exclude[] = $name; log_line($LOGFILE, "[KEEP][EXCLUDE] {$name}"); continue; }

  $path = $HOME_ROOT.'/'.$name;
  if (!is_dir($path) || is_link($path)) continue;

  // /etc/passwd ローカルホーム（/home/<username>）は保持
  if (in_array($name, $localUsers, true)) {
    $KEEP_local[] = $name;
    log_line($LOGFILE, "[KEEP][LOCAL] {$name}");
    continue;
  }

  // パターン YY-XXX-uid 以外は安全のためスキップ
  if (!preg_match('/^\d{2}-\d{3}-([A-Za-z0-9._\-]+)$/', $name, $m)) {
    $SKIP_badname[] = $name;
    log_line($LOGFILE, "[SKIP][BADNAME] {$name} ({$path})");
    continue;
  }
  $uidPart = $m[1];

  // 判定：退職集合に入っていれば INVALID2、現職集合に入っていれば KEEP
  if (isset($invalid2HomeSet[$path])) {
    $targets[] = $path;
    $targetsUid[$name] = $uidPart;
    $targetReason[$name] = 'INVALID2';
    $DEL_invalid2[] = $name;
    log_line($LOGFILE, "[DEL][INVALID2] {$name}");
    continue;
  }
  if (isset($validHomeSet[$path])) {
    $KEEP_valid[] = $name;
    log_line($LOGFILE, "[KEEP][VALID_DB] {$name}");
    continue;
  }

  // どちらにも無い → 在籍なし
  // 年齢フィルタ
  if ($ageLimitTs !== null) {
    $mtime = @filemtime($path) ?: 0;
    if ($mtime > $ageLimitTs) {
      $KEEP_new[] = $name;
      log_line($LOGFILE, "[KEEP][NEW] {$name} (mtime=".date('Y-m-d H:i:s', $mtime).")");
      continue;
    }
  }

  $targets[] = $path;
  $targetsUid[$name] = $uidPart;
  $targetReason[$name] = 'MISSING_DB';
  $DEL_missing[] = $name;
  log_line($LOGFILE, "[DEL][MISSING_DB] {$name}");
}

// ===== サマリ =====
log_line($LOGFILE, "-----------");
log_line($LOGFILE, sprintf("SUMMARY:"));
log_line($LOGFILE, sprintf("  DEL[INVALID2]: %d -> %s", count($DEL_invalid2), $DEL_invalid2 ? implode(',', $DEL_invalid2) : '-'));
log_line($LOGFILE, sprintf("  DEL[MISSING_DB]: %d -> %s", count($DEL_missing), $DEL_missing ? implode(',', $DEL_missing) : '-'));
log_line($LOGFILE, sprintf("  KEEP[LOCAL]: %d -> %s", count($KEEP_local), $KEEP_local ? implode(',', $KEEP_local) : '-'));
log_line($LOGFILE, sprintf("  KEEP[VALID_DB]: %d -> %s", count($KEEP_valid), $KEEP_valid ? implode(',', $KEEP_valid) : '-'));
log_line($LOGFILE, sprintf("  KEEP[EXCLUDE]: %d -> %s", count($KEEP_exclude), $KEEP_exclude ? implode(',', $KEEP_exclude) : '-'));
log_line($LOGFILE, sprintf("  KEEP[NEW]: %d -> %s", count($KEEP_new), $KEEP_new ? implode(',', $KEEP_new) : '-'));
log_line($LOGFILE, sprintf("  SKIP[BADNAME]: %d -> %s", count($SKIP_badname), $SKIP_badname ? implode(',', $SKIP_badname) : '-'));

// ===== 実行：ホーム削除／退避 =====
if (!$targets) {
  log_line($LOGFILE, "削除/退避の対象はありません。処理終了。");
  exit(0);
}

if (!$CONFIRM) {
  log_line($LOGFILE, "[DRY-RUN] 実行するには --confirm を付けてください。");
  if ($LDAP_DELETE) {
    foreach ($targets as $path) {
      $name = basename($path);
      $uid  = $targetsUid[$name] ?? null;
      if ($uid !== null) log_line($LOGFILE, "[DRY][LDAP-DEL] uid={$uid} (reason={$targetReason[$name]})");
    }
  }
  exit(0);
}

foreach ($targets as $path) {
  if ($TRASH_DIR) {
    $dst = rtrim($TRASH_DIR,'/').'/'.basename($path).'-'.date('YmdHis');
    log_line($LOGFILE, "[MOVE] {$path} -> {$dst}");
    if (!@rename($path, $dst)) log_line($LOGFILE, "[ERR ] MOVE 失敗: {$path}");
  } else {
    log_line($LOGFILE, "[DEL ] {$path}");
    if (!rrmdir($path)) log_line($LOGFILE, "[ERR ] DELETE 失敗: {$path}");
  }
}

// ===== 実行：LDAP削除（任意）=====
if ($LDAP_DELETE) {
  if (!function_exists('ldap_connect')) {
    log_line($LOGFILE, "[WARN] PHP LDAP拡張が無効のため、LDAP削除をスキップしました。");
  } else {
    [$ldapconn, $ldapErr] = ldap_connect_bind($LDAP_URL, $BIND_DN, $BIND_PASS);
    if (!$ldapconn) {
      log_line($LOGFILE, "[ERR ] LDAP接続失敗: {$ldapErr}");
    } else {
      foreach ($targets as $path) {
        $name = basename($path);
        $uid  = $targetsUid[$name] ?? null;
        if ($uid === null) continue;

        list($dn, $err) = ldap_find_dn_by_uid($ldapconn, $LDAP_BASE_DN, $LDAP_ATTR_UID, $uid);
        if ($err) {
          log_line($LOGFILE, "[ERR ] LDAP検索失敗 uid={$uid}: {$err}");
          continue;
        }
        if ($dn === null) {
          log_line($LOGFILE, "[INFO] LDAPに見つからず: uid={$uid} → 何もしません");
          continue;
        }

        if (!@ldap_delete($ldapconn, $dn)) {
          log_line($LOGFILE, "[ERR ] LDAP削除失敗 uid={$uid} dn={$dn}: ".ldap_error($ldapconn));
        } else {
          log_line($LOGFILE, "[LDAP-DEL] uid={$uid} dn={$dn} (reason={$targetReason[$name]})");
        }
      }
      @ldap_unbind($ldapconn);
    }
  }
}

log_line($LOGFILE, "=== prune-home DONE ===");


