#!/usr/bin/env php
<?php
/**
 * ldap_id_pass_from_postgres_set.php
 *
 * 概要:
 *  - DB（passwd_tnas × 情報個人）からユーザーを取得
 *  - kakasiで uid を生成し、/home/%02d-%03d-%s を必要に応じて作成
 *  - オプションで LDAP アカウントを追加/更新（既定はOFF）
 *
 * 作成しない条件:
 *  - /etc/passwd に同名ローカル（UID>=1000 & /home/...）
 *  - invalid >= 1（=1,2）
 *  - すでに /home/%02d-%03d-%s が存在
 *
 * 実行ホスト制限: ovs-010 / ovs-012 のみ
 * DB接続: 環境変数（.pgpass対応）
 *   PGHOST=127.0.0.1 / PGPORT=5432 / PGDATABASE=accounting / PGUSER=postgres / PGPASSWORD(任意)
 *
 * LDAP（任意・安全OFF）:
 *   --ldap-enable を付けた時のみ実行
 *   主なオプション:
 *     --ldap-url="ldap://127.0.0.1"
 *     --ldap-base-dn="ou=Users,dc=e-smile,dc=ne,dc=jp"
 *     --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp"
 *     --bind-pass="***"
 *     --ldap-posix=auto|off|force
 *       auto : NSSでuid/gidが解決できた時のみposixAccount付与（既定）
 *       off  : posixAccount付与なし（inetOrgPersonのみ）
 *       force: posixAccountを必ず付与（uidNumber/gidNumber/homeDirectory/loginShellが必要）
 *     --gid-default=100         （gidが無い時の既定）
 *     --login-shell="/bin/bash" （posixAccount時の既定）
 *
 * 既定SQL（JOIN済）: cmp_id, user_id, 姓かな, 名かな, ミドルネーム, invalid, samba_id, passwd_id を返す
 *   ※ --sql で上書き可能
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
    // DB/動作
    'sql::','home-root::','skel::','mode::','confirm','log::','min-local-uid::',

    // LDAP（任意）
    'ldap-enable',
    'ldap-url::','ldap-base-dn::','bind-dn::','bind-pass::',
    'ldap-posix::',       // auto|off|force
    'gid-default::',      // 既定: 100
    'login-shell::',      // 既定: /bin/bash
]);

// 既定SQL（JOIN: 現職/退職情報も取得。invalid>=1 は本スクリプトでスキップ）
$DEFAULT_SQL = 'SELECT 
  t.cmp_id       AS cmp_id,
  t.user_id      AS user_id,
  t.samba_id     AS samba_id,
  COALESCE(p.invalid,0) AS invalid,
  p."姓かな"     AS family_kana,
  p."名かな"     AS given_kana,
  COALESCE(p."ミドルネーム", \'\') AS middle_name,
  COALESCE(t.passwd_id, \'\') AS passwd_id,
  COALESCE(p."表示名", \'\')  AS display_name,
  COALESCE(p."電子メールアドレス", \'\') AS mail
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

// LDAP 設定
$LDAP_ENABLE   = isset($options['ldap-enable']);
$LDAP_URL      = $options['ldap-url']     ?? 'ldap://127.0.0.1';
$LDAP_BASE_DN  = $options['ldap-base-dn'] ?? '';
$BIND_DN       = $options['bind-dn']      ?? '';
$BIND_PASS     = $options['bind-pass']    ?? '';
$LDAP_POSIX    = strtolower($options['ldap-posix'] ?? 'auto'); // auto|off|force
$GID_DEFAULT   = isset($options['gid-default']) ? (int)$options['gid-default'] : 100;
$LOGIN_SHELL   = $options['login-shell']  ?? '/bin/bash';

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

// ===== SSHA ハッシュ生成（平文→{SSHA}）=====
function ssha_hash(string $password): string {
    $salt = random_bytes(4);
    $hash = sha1($password.$salt, true).$salt;
    return '{SSHA}'.base64_encode($hash);
}

// ===== LDAPユーティリティ =====
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

// ===== 事前チェック =====
if (!is_dir($HOME_ROOT)) abortx("home-root が存在しません: {$HOME_ROOT}", $LOGFILE);
if (!is_dir($SKEL_DIR))  abortx("skel ディレクトリが存在しません: {$SKEL_DIR}", $LOGFILE);
if ($LDAP_ENABLE && $LDAP_BASE_DN === '') abortx("LDAPを有効化していますが --ldap-base-dn が未指定です。", $LOGFILE);

log_line($LOGFILE, "=== START add-home(+LDAP) ===");
log_line($LOGFILE, "HOST      : {$hostname}");
log_line($LOGFILE, "HOME_ROOT : {$HOME_ROOT}");
log_line($LOGFILE, "SKEL      : {$SKEL_DIR}");
log_line($LOGFILE, "MODE      : ".decoct($MODE)." (".$MODE.")");
log_line($LOGFILE, "CONFIRM   : ".($CONFIRM ? 'YES (execute)' : 'NO (dry-run)'));
log_line($LOGFILE, "LDAP      : ".($LDAP_ENABLE ? "ENABLED url={$LDAP_URL} base={$LDAP_BASE_DN} posix={$LDAP_POSIX}" : "disabled"));
log_line($LOGFILE, "-----------");

// /etc/passwd のローカル保持
$LOCAL_KEEP = load_local_passwd_users($HOME_ROOT, $MIN_LOCAL);
if ($LOCAL_KEEP) log_line($LOGFILE, "[INFO] /etc/passwd local keep: ".implode(',', array_keys($LOCAL_KEEP)));

// DB取得
try { $pdo = get_pg_pdo(); }
catch (Throwable $e) { abortx("DB接続失敗: ".$e->getMessage(), $LOGFILE); }

try { $rows = $pdo->query($SQL)->fetchAll(); }
catch (Throwable $e) { abortx("SQL実行失敗: ".$e->getMessage(), $LOGFILE); }

if (!$rows) { log_line($LOGFILE, "[INFO] 対象0件。終了。"); exit(0); }

// LDAP接続（必要な場合）
$ldapconn = null;
if ($LDAP_ENABLE) {
    [$ldapconn, $ldapErr] = ldap_connect_bind($LDAP_URL, $BIND_DN, $BIND_PASS);
    if (!$ldapconn) abortx("LDAP接続失敗: {$ldapErr}", $LOGFILE);
}

// 1件ずつ
foreach ($rows as $row) {
    $cmp_id      = (int)($row['cmp_id'] ?? 0);
    $user_id     = (int)($row['user_id'] ?? 0);
    $familyKana  = (string)($row['family_kana'] ?? '');
    $givenKana   = (string)($row['given_kana'] ?? '');
    $middleName  = trim((string)($row['middle_name'] ?? ''));
    $invalid     = (int)($row['invalid'] ?? 0);
    $samba_id    = isset($row['samba_id']) ? trim((string)$row['samba_id']) : '';
    $passwd_id   = (string)($row['passwd_id'] ?? '');
    $displayName = (string)($row['display_name'] ?? '');
    $mail        = (string)($row['mail'] ?? '');

    if ($cmp_id<=0 || $user_id<=0 || $familyKana==='' || $givenKana==='') {
        log_line($LOGFILE, "[SKIP][BADROW] 必須項目不足 cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }

    // 追加禁止：invalid>=1
    if ($invalid >= 1) {
        log_line($LOGFILE, "[SKIP][INVALID>=1] cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }

    // 追加禁止：/etc/passwd にローカル存在（samba_id が分かるなら参照）
    if ($samba_id !== '' && isset($LOCAL_KEEP[$samba_id])) {
        log_line($LOGFILE, "[SKIP][LOCAL] /etc/passwdに存在 => 追加しない: {$samba_id}");
        continue;
    }

    // uid と homeDir を生成
    $uid = kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName;
    $homePath = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid);

    // ---- ホーム作成 ----
    if (is_dir($homePath)) {
        log_line($LOGFILE, "[KEEP][EXIST] HOME: {$homePath}");
    } else {
        if (!$CONFIRM) {
            log_line($LOGFILE, "[DRY][MKDIR] {$homePath} (mode ".decoct($MODE).") from {$SKEL_DIR}");
        } else {
            if (!@mkdir($homePath, $MODE, true)) {
                log_line($LOGFILE, "[ERR ][MKDIR] 失敗: {$homePath}");
            } else {
                // skel コピー
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($SKEL_DIR, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($it as $item) {
                    $rel = substr($item->getPathname(), strlen($SKEL_DIR));
                    $dst = $homePath.$rel;
                    if ($item->isDir()) @mkdir($dst, $MODE, true);
                    else @copy($item->getPathname(), $dst);
                }
                log_line($LOGFILE, "[ADD ][HOME] {$homePath} (mode=".decoct($MODE).")");
            }
        }
    }

    // ---- LDAP 追加/更新（オプション有効時のみ）----
    if (!$LDAP_ENABLE) continue;

    // DN 構築（uid は kakasi 由来）
    if (!function_exists('ldap_escape')) {
        // PHP古い場合の簡易DNエスケープ（最低限）
        function ldap_escape($subject, $ignore = '', $flags = 0) {
            $search = [',', '+', '"', '\\', '<', '>', ';', '#', '=',];
            $replace= array_map(function($c){ return '\\'.dechex(ord($c)); }, $search);
            return str_replace($search, $replace, $subject);
        }
    }
    $rdnUid = ldap_escape($uid, '', LDAP_ESCAPE_DN);
    $dn = "uid={$rdnUid},{$LDAP_BASE_DN}";

    // 表示名・氏名
    $sn   = $familyKana; // かなをそのまま使う/別途「漢字姓」があるなら差し替え可
    $given= $givenKana;
    $cn   = $displayName !== '' ? $displayName : ($sn.' '.$given);

    // パスワード（ハッシュ判定）
    $userPassword = '';
    if ($passwd_id !== '') {
        if (preg_match('/^\{[A-Z0-9]+\}/', $passwd_id)) $userPassword = $passwd_id; // 既にハッシュ
        else $userPassword = ssha_hash($passwd_id); // 平文 → SSHA
    }

    // 既存チェック（DN直読）
    $exists = false;
    $sr = @ldap_read($ldapconn, $dn, '(objectClass=*)', ['dn']);
    if ($sr) {
        $e = @ldap_get_entries($ldapconn, $sr);
        $exists = ($e && ($e['count'] > 0));
    }

    // 共有属性（更新対象・追加対象のベース）
    $entry_common = [
        'cn'          => $cn,
        'sn'          => $sn,
        'givenName'   => $given,
        'displayName' => $cn,
    ];
    if ($mail !== '') $entry_common['mail'] = $mail;
    if ($userPassword !== '') $entry_common['userPassword'] = $userPassword;

    // posixAccount をどうするか
    $add_posix = false;
    $uidNumber = null;
    $gidNumber = null;
    $loginShell= $LOGIN_SHELL;
    $homeDirLdap = $homePath; // LDAPのhomeDirectoryも同じパスに

    if ($LDAP_POSIX === 'force') {
        $add_posix = true;
        // uidNumber/gidNumber は NSS から取れない場合、明示指定が無いと未定義になりうる。
        // ここでは gid は既定を使い、uid は未解決なら posixAccount 未付与にフォールバック。
    }

    if ($LDAP_POSIX === 'auto' || $LDAP_POSIX === 'force') {
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($uid);
            if (is_array($pw)) {
                $uidNumber = (int)$pw['uid'];
                $gidNumber = (int)$pw['gid'];
            }
        }
        if ($gidNumber === null) $gidNumber = $GID_DEFAULT;
        if ($uidNumber !== null) $add_posix = true; // uidNumber が判明した時のみ付与
    }

    // 追加 or 更新
    if ($exists) {
        // 既存 → uidは変更しない。指定属性のみ置換
        $entry_update = $entry_common;

        if ($add_posix) {
            $entry_update['loginShell']   = $loginShell;
            $entry_update['homeDirectory']= $homeDirLdap;
            if ($uidNumber !== null) $entry_update['uidNumber'] = (string)$uidNumber;
            if ($gidNumber !== null) $entry_update['gidNumber'] = (string)$gidNumber;
        }

        if (!$CONFIRM) {
            printf("[DRY][LDAP-UPD] [%02d-%03d] [%-20s] %s\n",
                $cmp_id, $user_id, $uid, $cn);
        } else {
            if (!@ldap_mod_replace($ldapconn, $dn, $entry_update)) {
                echo "Err! [$uid] LDAP 更新失敗: ".ldap_error($ldapconn)."\n";
            } else {
                printf("Up!  [%02d-%03d] [%-20s] 属性更新 [%s]\n",
                    $cmp_id, $user_id, $uid, $cn);
            }
        }

    } else {
        // 新規追加
        $entry_add = $entry_common;
        $entry_add['uid'] = $uid;

        // objectClass は必要最小限 + 条件で posixAccount
        $ocs = ['inetOrgPerson'];
        if ($add_posix) $ocs[] = 'posixAccount';
        // shadowAccount を使う場合はここに追加
        // $ocs[] = 'shadowAccount';
        $entry_add['objectClass'] = $ocs;

        if ($add_posix) {
            if ($uidNumber === null) {
                // uidNumber未解決なら posixAccount 付与は危険なので落とす
                $idx = array_search('posixAccount', $entry_add['objectClass'], true);
                if ($idx !== false) array_splice($entry_add['objectClass'], $idx, 1);
            } else {
                $entry_add['uidNumber']    = (string)$uidNumber;
                $entry_add['gidNumber']    = (string)$gidNumber;
                $entry_add['homeDirectory']= $homeDirLdap;
                $entry_add['loginShell']   = $loginShell;
            }
        }

        if (!$CONFIRM) {
            printf("[DRY][LDAP-ADD] [%02d-%03d] [%-20s] %s\n",
                $cmp_id, $user_id, $uid, $cn);
        } else {
            if (!@ldap_add($ldapconn, $dn, $entry_add)) {
                echo "Err! [$uid] LDAP 追加失敗: ".ldap_error($ldapconn)."\n";
            } else {
                printf("Add! [%02d-%03d] [%-20s] 新規登録 [%s]\n",
                    $cmp_id, $user_id, $uid, $cn);
            }
        }
    }
}

// 後処理
if ($ldapconn) @ldap_unbind($ldapconn);
log_line($LOGFILE, "=== DONE add-home(+LDAP) ===");


