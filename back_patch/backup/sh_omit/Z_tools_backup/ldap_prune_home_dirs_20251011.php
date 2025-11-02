#!/usr/bin/env php
<?php
/**
 * ldap_prune_home_dirs.php
 *  - 「情報個人.invalid=2」および DB 不在(MISSING_DB)のホームを削除対象とし、
 *    退避(MOVE)時は rename に失敗した場合（別FS等）に rsync フォールバックを行う。
 *  - 退避先(TRASH_DIR)を指定しなければ物理削除（rrmdir）。
 *  - ホスト制限(ovs-010 / ovs-012)、DRY-RUN(--confirm 無し)対応、ログ出力対応。
 *  - 追加側と同じホーム命名規則：/home/%02d-%03d-%s（%s = kakasi(姓かな)-kakasi(名かな)+ミドルネーム）
 *  - SQLは NOWDOC で安全に定義。
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
  'sql-valid::','sql-invalid::',
  'ldap-delete','ldap-url::','bind-dn::','bind-pass::','ldap-base-dn::','ldap-attr-uid::',
]);

$HOME_ROOT    = rtrim($options['home-root'] ?? '/home', '/');
$EXCLUDE      = array_filter(array_map('trim', explode(',', $options['exclude'] ?? 'lost+found,skel')));
$CONFIRM      = isset($options['confirm']);
$TRASH_DIR    = $options['trash'] ?? null;
$AGE_DAYS     = isset($options['age-days']) ? (int)$options['age-days'] : null;
$MIN_LOCAL_UID= isset($options['min-local-uid']) ? (int)$options['min-local-uid'] : 1000;
$LOGFILE      = $options['log'] ?? null;

// ===== DB接続（env/.pgpass対応）=====
$envHost = getenv('PGHOST')     ?: '127.0.0.1';
$envPort = getenv('PGPORT')     ?: '5432';
$envDb   = getenv('PGDATABASE') ?: 'accounting';
$envUser = getenv('PGUSER')     ?: 'postgres';
$envPass = getenv('PGPASSWORD'); // nullなら .pgpass 利用

$PG_DSN  = sprintf('pgsql:host=%s;port=%s;dbname=%s', $envHost, $envPort, $envDb);
$PG_USER = $envUser;
$PG_PASS = $envPass ?: null;

// ===== 既定SQL（NOWDOC） =====
// 在籍（invalid < 2）からホーム候補を構築
$DEFAULT_SQL_VALID = <<<'SQL'
SELECT 
  t.cmp_id AS cmp_id,
  t.user_id AS user_id,
  COALESCE(p.invalid,0) AS invalid,
  p."姓かな" AS family_kana,
  p."名かな" AS given_kana,
  COALESCE(p."ミドルネーム", '') AS middle_name
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> ''
  AND COALESCE(p.invalid,0) < 2
SQL;

// 退職（invalid=2）からホーム候補を構築（削除対象）
$DEFAULT_SQL_INVALID = <<<'SQL'
SELECT 
  t.cmp_id AS cmp_id,
  t.user_id AS user_id,
  p.invalid AS invalid,
  p."姓かな" AS family_kana,
  p."名かな" AS given_kana,
  COALESCE(p."ミドルネーム", '') AS middle_name
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> ''
  AND p.invalid = 2
SQL;

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
function kanaToRomaji($kana) {
  $kana = escapeshellarg($kana);
  $romaji = shell_exec("echo $kana | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
  // ここはクォート崩れに注意（正解は下の行）
  $romaji = strtolower(str_replace([' ', "'"], '', trim($romaji)));
  return $romaji;
}
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
function rrmdir(string $dir): bool {
  if (strlen($dir) < 10) return false; // 過剰防御
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
  if (!function_exists('ldap_escape')) {
    function ldap_escape($subject, $ignore = '', $flags = 0) {
      $search = ['*', '(', ')', '\\', "\x00"]; // 正しいクォート
      $replace = array_map(function($c){ return '\\'.str_pad(dechex(ord($c)),2,'0',STR_PAD_LEFT); }, $search);
      return str_replace($search, $replace, $subject);
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

$validHomeSet = [];
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

$invalid2HomeSet = [];
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

log_line($LOGFILE, "=== prune-home START ===");
log_line($LOGFILE, "HOSTNAME  : {$hostname}");
log_line($LOGFILE, "HOME_ROOT : {$HOME_ROOT}");
log_line($LOGFILE, "CONFIRM   : ".($CONFIRM ? 'YES (execute)' : 'NO (dry-run)'));
log_line($LOGFILE, "TRASH_DIR : ".($TRASH_DIR ?: '(なし)'));
log_line($LOGFILE, "EXCLUDE   : ".(implode(', ', $EXCLUDE) ?: '(なし)'));
log_line($LOGFILE, "AGE_DAYS  : ".($AGE_DAYS !== null ? $AGE_DAYS : '(制限なし)'));
log_line($LOGFILE, "[INFO] valid homes: {$validCount} / invalid2 homes: {$invalid2Count}");
log_line($LOGFILE, "-----------");

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

$targets = [];
$targetsUid = [];
$targetReason = [];

foreach ($entries as $name) {
  if ($name === '.' || $name === '..') continue;
  if (in_array($name, $EXCLUDE, true)) { $KEEP_exclude[] = $name; log_line($LOGFILE, "[KEEP][EXCLUDE] {$name}"); continue; }

  $path = $HOME_ROOT.'/'.$name;
  if (!is_dir($path) || is_link($path)) continue;

  if (in_array($name, $localUsers, true)) {
    $KEEP_local[] = $name;
    log_line($LOGFILE, "[KEEP][LOCAL] {$name}");
    continue;
  }

  if (!preg_match('/^\\d{2}-\\d{3}-([A-Za-z0-9._\\-]+)$/', $name, $m)) {
    $SKIP_badname[] = $name;
    log_line($LOGFILE, "[SKIP][BADNAME] {$name} ({$path})");
    continue;
  }
  $uidPart = $m[1];

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

log_line($LOGFILE, "-----------");
log_line($LOGFILE, sprintf("SUMMARY:"));
log_line($LOGFILE, sprintf("  DEL[INVALID2]: %d -> %s", count($DEL_invalid2), $DEL_invalid2 ? implode(',', $DEL_invalid2) : '-'));
log_line($LOGFILE, sprintf("  DEL[MISSING_DB]: %d -> %s", count($DEL_missing), $DEL_missing ? implode(',', $DEL_missing) : '-'));
log_line($LOGFILE, sprintf("  KEEP[LOCAL]: %d -> %s", count($KEEP_local), $KEEP_local ? implode(',', $KEEP_local) : '-'));
log_line($LOGFILE, sprintf("  KEEP[VALID_DB]: %d -> %s", count($KEEP_valid), $KEEP_valid ? implode(',', $KEEP_valid) : '-'));
log_line($LOGFILE, sprintf("  KEEP[EXCLUDE]: %d -> %s", count($KEEP_exclude), $KEEP_exclude ? implode(',', $KEEP_exclude) : '-'));
log_line($LOGFILE, sprintf("  KEEP[NEW]: %d -> %s", count($KEEP_new), $KEEP_new ? implode(',', $KEEP_new) : '-'));
log_line($LOGFILE, sprintf("  SKIP[BADNAME]: %d -> %s", count($SKIP_badname), $SKIP_badname ? implode(',', $SKIP_badname) : '-'));

if (!$targets) { log_line($LOGFILE, "削除/退避の対象はありません。処理終了。"); exit(0); }

$FS_MOVE_OK = $FS_MOVE_ERR = 0;
$FS_DEL_OK  = $FS_DEL_ERR  = 0;
$LDAP_DEL_OK = $LDAP_DEL_ERR = $LDAP_NOTFOUND = 0;

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

// ===== 退避 or 削除の実行（rsync フォールバック付き） =====
foreach ($targets as $path) {
  if ($TRASH_DIR) {
    $dst = rtrim($TRASH_DIR,'/').'/'.basename($path).'-'.date('YmdHis');
    log_line($LOGFILE, "[MOVE] {$path} -> {$dst}");
    $ok = @rename($path, $dst);
    if (!$ok) {
      // rename 失敗: 別FSの可能性 → rsync フォールバック
      @mkdir($dst, 0700, true);
      $srcEsc = escapeshellarg(rtrim($path, '/').'/');
      $dstEsc = escapeshellarg(rtrim($dst,  '/').'/');
      $cmd = "rsync -aHAX --numeric-ids {$srcEsc} {$dstEsc}";
      exec($cmd, $out, $rc);
      if ($rc === 0) {
        if (!rrmdir($path)) {
          $FS_MOVE_ERR++; log_line($LOGFILE, "[ERR ] MOVE(rsync後) 元削除失敗: {$path}");
        } else {
          $FS_MOVE_OK++; log_line($LOGFILE, "[MOVE][rsync] {$path} -> {$dst}");
        }
      } else {
        $FS_MOVE_ERR++; log_line($LOGFILE, "[ERR ] rsync失敗 rc={$rc}: {$path}");
      }
    } else {
      $FS_MOVE_OK++;
    }
  } else {
    log_line($LOGFILE, "[DEL ] {$path}");
    if (!rrmdir($path)) { $FS_DEL_ERR++; log_line($LOGFILE, "[ERR ] DELETE 失敗: {$path}"); }
    else { $FS_DEL_OK++; }
  }
}

// ===== LDAP アカウント削除 =====
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
        if ($err) { $LDAP_DEL_ERR++; log_line($LOGFILE, "[ERR ] LDAP検索失敗 uid={$uid}: {$err}"); continue; }
        if ($dn === null) { $LDAP_NOTFOUND++; log_line($LOGFILE, "[INFO] LDAPに見つからず: uid={$uid} → 何もしません"); continue; }

        if (!@ldap_delete($ldapconn, $dn)) { $LDAP_DEL_ERR++; log_line($LOGFILE, "[ERR ] LDAP削除失敗 uid={$uid} dn={$dn}: ".ldap_error($ldapconn)); }
        else { $LDAP_DEL_OK++; log_line($LOGFILE, "[LDAP-DEL] uid={$uid} dn={$dn} (reason={$targetReason[$name]})"); }
      }
      @ldap_unbind($ldapconn);
    }
  }
}

log_line($LOGFILE, "-----------");
log_line($LOGFILE, sprintf("RESULTS: FS_MOVE_OK=%d, FS_MOVE_ERR=%d, FS_DEL_OK=%d, FS_DEL_ERR=%d", $FS_MOVE_OK, $FS_MOVE_ERR, $FS_DEL_OK, $FS_DEL_ERR));
if ($LDAP_DELETE) {
  log_line($LOGFILE, sprintf("RESULTS: LDAP_DEL_OK=%d, LDAP_DEL_ERR=%d, LDAP_NOTFOUND=%d", $LDAP_DEL_OK, $LDAP_DEL_ERR, $LDAP_NOTFOUND));
}
log_line($LOGFILE, "=== prune-home DONE ===");
