#!/usr/bin/env php
<?php
/**
 * ldap_id_pass_from_postgres_set.php (final, 2025-10-11)
 *
 * DB（passwd_tnas × 情報個人）から現職者のレコードを取得し、
 * /home/%02d-%03d-%s のホームを必要に応じて作成・整備。
 * さらに --ldap 指定かつ LDAP_URL/BIND_DN/BIND_PW が与えられている場合に
 * LDAP（inetOrgPerson + posixAccount + shadowAccount）を upsert します。
 *
 * 既定は DRY-RUN（確認のみ）。--confirm で実行。
 * uid/gid は DBに無い場合、規則で補完:
 *   uidNumber = cmp_id*10000 + user_id
 *   gidNumber = 2000 + cmp_id
 * それでも欠ける場合は、既存LDAPから拾う救済を実施（任意）。
 *
 * 使い方（例）:
 *  # ドライラン（確認のみ）
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php
 *
 *  # 本実行 + LDAP 反映（LDAPS）
 *    HOME_ROOT=/home SKEL=/etc/skel MODE=750 \
 *    LDAP_URL='ldaps://ovs-012.e-smile.local' \
 *    BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \
 *    BIND_PW='********' \
 *    PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp' \
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

// ===== 実行ホスト制限（元ソース準拠）=====
$ALLOWED_HOSTS = ['ovs-010','ovs-012'];
$hostname  = gethostname() ?: php_uname('n');
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
    'ldap',            // 付けたら LDAP を有効化（LDAP_URL/BIND_DN/BIND_PW が必要）
    'log::',           // ログファイル（任意）
    'min-local-uid::', // 既定: 1000
]);

// ===== 既定SQL（JOIN: 現職/退職情報も取得。invalid>=1 はスキップ）=====
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
  COALESCE(p."電子メールアドレス", \'\') AS mail,
  NULL::text AS home_directory,     -- あればここに埋める（無ければ後で生成）
  NULL::text AS login_shell,        -- あればここに埋める（無ければ /bin/bash）
  NULL::int  AS uid_number,         -- あればここに埋める（無ければ規則で補完）
  NULL::int  AS gid_number,         -- 同上
  NULL::text AS plain_password,     -- あれば SSHA 生成に利用
  NULL::text AS password_ssha       -- 既に SSHA があればそのまま利用
FROM passwd_tnas AS t
JOIN "情報個人" AS p
  ON t.cmp_id = p.cmp_id AND t.user_id = p.user_id
WHERE t.samba_id IS NOT NULL AND t.samba_id <> \'\'
  AND COALESCE(p.invalid,0)=0
ORDER BY t.cmp_id, t.user_id';

// ===== 設定 =====
$HOME_ROOT = rtrim($options['home-root'] ?? getenv('HOME_ROOT') ?: '/home', '/');
$SKEL_DIR  = rtrim($options['skel']      ?? getenv('SKEL')      ?: '/etc/skel', '/');
$MODE_STR  = (string)($options['mode']   ?? getenv('MODE')      ?: '0750');
$MODE      = octdec(ltrim($MODE_STR, '0')) ?: 0750;
$DRY_RUN   = !isset($options['confirm']);
$LOGFILE   = (string)($options['log'] ?? '');
$MIN_LOCAL_UID = (int)($options['min-local-uid'] ?? 1000);

// LDAP（実行条件）
$LDAP_URL  = getenv('LDAP_URL') ?: '';
$BIND_DN   = getenv('BIND_DN')  ?: '';
$BIND_PW   = getenv('BIND_PW')  ?: '';
$PEOPLE_OU = getenv('PEOPLE_OU') ?: 'ou=Users,dc=e-smile,dc=ne,dc=jp';
$LDAP_ENABLED = (isset($options['ldap']) && $LDAP_URL && $BIND_DN && $BIND_PW);

// ===== 出力（既存ログ体裁）=====
echo "=== START add-home(+LDAP) ===\n";
printf("HOST      : %s\n", $hostname);
printf("HOME_ROOT : %s\n", $HOME_ROOT);
printf("SKEL      : %s\n", $SKEL_DIR);
printf("MODE      : %s (%d)\n", $MODE_STR, $MODE);
printf("CONFIRM   : %s (%s)\n", $DRY_RUN ? 'NO' : 'YES', $DRY_RUN ? 'dry-run' : 'execute');
printf("LDAP      : %s\n", $LDAP_ENABLED ? 'enabled' : 'disabled');
echo "-----------\n";

// ===== ログ関数 =====
function log_line(?string $file, string $msg): void {
    $line = $msg;
    if ($file) {
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    }
    echo $line . PHP_EOL;
}

// ===== /etc/passwd ローカル（UID>=min & /home/...）=====
function load_local_passwd_users(string $homeRoot = '/home', int $minUid = 1000): array {
    $keep = [];
    $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $keep;
    foreach ($lines as $line) {
        $cols = explode(':', $line);
        if (count($cols) < 7) continue;
        [$name, , $uid, , , $home, ] = [$cols[0], $cols[1], (int)$cols[2], $cols[3], $cols[4], $cols[5], $cols[6]];
        if ($uid >= $minUid && is_string($home) && str_starts_with($home, rtrim($homeRoot,'/').'/')) {
            $keep[$name] = $home;
        }
    }
    return $keep;
}

// ===== kakasi: かな → ローマ字（英小文字、空白と'除去）=====
function kanaToRomaji($kana) {
    $kana = escapeshellarg($kana);
    $romaji = shell_exec("echo $kana | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
    $romaji = strtolower(str_replace([' ', "'"], '', trim((string)$romaji)));
    return $romaji ?: '';
}

// ===== 文字列 starts_with polyfill（PHP8未満互換）=====
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// ===== SSHA 生成 =====
function make_ssha_password(string $plain): string {
    $salt = random_bytes(8);
    $hash = sha1($plain . $salt, true);
    return '{SSHA}' . base64_encode($hash . $salt);
}

// ===== LDAP: escape（存在しない環境向け）=====
if (!function_exists('ldap_escape')) {
    function ldap_escape($subject, $ignore = '', $flags = 0) {
        $search = ['\\', ',', '+', '"', '<', '>', ';', '#', '='];
        $replace = array_map(fn($c) => '\\' . str_pad(dechex(ord($c)), 2, '0', STR_PAD_LEFT), $search);
        return str_replace($search, $replace, $subject);
    }
}

// ===== DB接続（PDO; 環境変数 or 既定）=====
function open_pdo(): PDO {
    $dsn  = getenv('PG_DSN');
    if (!$dsn) {
        $host = getenv('PGHOST') ?: '127.0.0.1';
        $port = getenv('PGPORT') ?: '5432';
        $db   = getenv('PGDATABASE') ?: 'accounting';
        $dsn  = "pgsql:host={$host};port={$port};dbname={$db}";
    }
    $usr = getenv('PGUSER') ?: null;
    $pwd = getenv('PGPASSWORD') ?: null;
    $opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    return new PDO($dsn, $usr, $pwd, $opt);
}

// ===== LDAP 接続 =====
function ldap_connect_bind(string $url, string $bindDn, string $bindPw) {
    $conn = @ldap_connect($url);
    if (!$conn) throw new RuntimeException("ldap_connect failed: $url");
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (!@ldap_bind($conn, $bindDn, $bindPw)) {
        throw new RuntimeException("ldap_bind failed: " . ldap_error($conn));
    }
    return $conn;
}

// ===== LDAP upsert =====
function ldap_upsert_user($conn, string $peopleOu, array $u, bool $dry, string $logfile = ''): void {
    $uid = (string)$u['uid'];
    $dn  = "uid={$uid},".$peopleOu;

    // 既存判定
    $exists = false;
    $sr = @ldap_search($conn, $peopleOu, sprintf('(uid=%s)', ldap_escape($uid, '', LDAP_ESCAPE_FILTER)), ['dn']);
    if ($sr !== false) {
        $entries = ldap_get_entries($conn, $sr);
        $exists = ($entries && $entries['count'] > 0);
    }

    $attrs = [
        'uid'           => $uid,
        'cn'            => (string)($u['cn'] ?? $uid),
        'sn'            => (string)($u['sn'] ?? $uid),
        'givenName'     => (string)($u['givenName'] ?? ($u['given_name'] ?? $uid)),
        'displayName'   => (string)($u['cn'] ?? $uid),
        'mail'          => (string)($u['mail'] ?? ''),
        'uidNumber'     => (string)$u['uidNumber'],
        'gidNumber'     => (string)$u['gidNumber'],
        'homeDirectory' => (string)$u['homeDirectory'],
        'loginShell'    => (string)($u['loginShell'] ?? '/bin/bash'),
        'objectClass'   => ['inetOrgPerson','posixAccount','shadowAccount'],
        'userPassword'  => (string)$u['userPassword'],
    ];

    if ($exists) {
        if ($dry) { log_line($logfile, "[LDAP][DRY][MOD] {$dn}"); return; }
        $ok = @ldap_mod_replace($conn, $dn, $attrs);
        if (!$ok) log_line($logfile, "[LDAP][ERROR] modify {$dn}: ".ldap_error($conn));
        else      log_line($logfile, "[LDAP][OK  ][MOD] {$dn}");
    } else {
        if ($dry) { log_line($logfile, "[LDAP][DRY][ADD] {$dn}"); return; }
        $ok = @ldap_add($conn, $dn, $attrs);
        if (!$ok) log_line($logfile, "[LDAP][ERROR] add {$dn}: ".ldap_error($conn));
        else      log_line($logfile, "[LDAP][OK  ][ADD] {$dn}");
    }
}

// ===== 1) /etc/passwd のローカル keep 情報表示 =====
$localKeep = load_local_passwd_users($HOME_ROOT, $MIN_LOCAL_UID);
$keepNames = implode(',', array_keys($localKeep));
echo "[INFO] /etc/passwd local keep: " . ($keepNames !== '' ? $keepNames : '-') . "\n";

// ===== 2) ユーザー一覧の取得 =====
$sql = (string)($options['sql'] ?? $DEFAULT_SQL);
$rows = [];
try {
    $pdo = open_pdo();
    $st  = $pdo->query($sql);
    while ($r = $st->fetch()) $rows[] = $r;
} catch (Throwable $e) {
    log_line($LOGFILE, "[ERROR] DB connect/query failed: ".$e->getMessage());
    log_line($LOGFILE, "=== DONE add-home(+LDAP) ===");
    exit(1);
}

// ===== 3) ホーム作成/整備 & LDAP 用データ整形 =====
$usersForLdap = [];
foreach ($rows as $row) {
    $cmp_id     = (int)($row['cmp_id'] ?? 0);
    $user_id    = (int)($row['user_id'] ?? 0);
    $invalid    = (int)($row['invalid'] ?? 0);
    $samba_id   = isset($row['samba_id']) ? trim((string)$row['samba_id']) : '';
    $familyKana = (string)($row['family_kana'] ?? '');
    $givenKana  = (string)($row['given_kana'] ?? '');
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $display    = (string)($row['display_name'] ?? '');
    $mail       = (string)($row['mail'] ?? '');

    if ($cmp_id<=0 || $user_id<=0 || $familyKana==='' || $givenKana==='') {
        log_line($LOGFILE, "[SKIP][BADROW] 必須不足 cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }
    if ($invalid >= 1) {
        log_line($LOGFILE, "[SKIP][INVALID>=1] cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }

    // uid（samba_id 優先。無ければ かな から生成）
    $uid_base = $samba_id !== '' ? $samba_id : kanaToRomaji($familyKana . $givenKana . ($middleName ? $middleName : ''));
    if ($uid_base === '') {
        log_line($LOGFILE, "[SKIP][NOUID] cmp_id={$cmp_id} user_id={$user_id}");
        continue;
    }
    $uid = $uid_base;

    // HOME パス（列にあれば優先。無ければ生成）
    $homeFromDb = isset($row['home_directory']) ? trim((string)$row['home_directory']) : '';
    $homePath = $homeFromDb !== '' ? $homeFromDb : sprintf("%s/%02d-%03d-%s", $HOME_ROOT, $cmp_id, $user_id, $uid);

    // シェル
    $loginShell = isset($row['login_shell']) && trim((string)$row['login_shell']) !== '' ? (string)$row['login_shell'] : '/bin/bash';

    // 表示名/氏名
    $cn  = $display !== '' ? $display : ($familyKana . ' ' . $givenKana . ($middleName ? ' '.$middleName : ''));
    $sn  = $familyKana !== '' ? $familyKana : $uid;
    $gn  = $givenKana  !== '' ? $givenKana  : $uid;

    // HOME 整備
    if (is_dir($homePath)) {
        echo "[KEEP][EXIST] HOME: {$homePath}\n";
        $cur = @fileperms($homePath) & 0777;
        if ($cur !== $MODE) {
            if ($DRY_RUN) echo "[CHMOD][DRY] HOME: {$homePath} -> ".decoct($MODE)."\n";
            else @chmod($homePath, $MODE);
        }
    } else {
        if ($DRY_RUN) {
            echo "[CREATE][DRY] HOME: {$homePath}\n";
        } else {
            @mkdir($homePath, $MODE, true);
            if (is_dir($SKEL_DIR)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($SKEL_DIR, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($it as $item) {
                    $rel = substr($item->getPathname(), strlen($SKEL_DIR));
                    $dst = $homePath . $rel;
                    if ($item->isDir()) {
                        @mkdir($dst, $MODE, true);
                    } else {
                        @copy($item->getPathname(), $dst);
                    }
                }
            }
            echo "[ADD ][HOME] {$homePath} (mode=".decoct($MODE).")\n";
        }
    }

    // ===== LDAP 用データ（uid/gid 補完ロジック）=====
    // 1) DBにあればそれを使う
    $uidNumber = isset($row['uid_number']) ? (int)$row['uid_number'] : 0;
    $gidNumber = isset($row['gid_number']) ? (int)$row['gid_number'] : 0;
    // 2) 無ければ規則で補完
    if ($uidNumber <= 0) $uidNumber = ($cmp_id * 10000) + $user_id;
    if ($gidNumber <= 0) $gidNumber = 2000 + $cmp_id;

    // 3) それでも0/負なら既存LDAPから拾う（救済）
    if ($LDAP_ENABLED && ($uidNumber <= 0 || $gidNumber <= 0)) {
        try {
            static $conn_for_lookup = null;
            if ($conn_for_lookup === null) {
                $conn_for_lookup = ldap_connect_bind(getenv('LDAP_URL'), getenv('BIND_DN'), getenv('BIND_PW'));
            }
            $sr = @ldap_search(
                $conn_for_lookup,
                getenv('PEOPLE_OU') ?: 'ou=Users,dc=e-smile,dc=ne,dc=jp',
                sprintf('(uid=%s)', ldap_escape($uid,'',LDAP_ESCAPE_FILTER)),
                ['uidNumber','gidNumber']
            );
            if ($sr) {
                $e = ldap_get_entries($conn_for_lookup, $sr);
                if ($e && $e['count'] > 0) {
                    if ($uidNumber <= 0 && isset($e[0]['uidnumber'][0])) $uidNumber = (int)$e[0]['uidnumber'][0];
                    if ($gidNumber <= 0 && isset($e[0]['gidnumber'][0])) $gidNumber = (int)$e[0]['gidnumber'][0];
                }
            }
        } catch (\Throwable $x) {
            // 取得失敗時はこのまま（下でSKIPログ）
        }
    }

    if ($LDAP_ENABLED && $uidNumber > 0 && $gidNumber > 0) {
        // パスワード（SSHAを優先。無ければ plain → SSHA。どちらも無ければランダム）
        $password_ssha = isset($row['password_ssha']) ? trim((string)$row['password_ssha']) : '';
        $plain         = isset($row['plain_password']) ? (string)$row['plain_password'] : '';
        if ($password_ssha === '') {
            $password_ssha = $plain !== '' ? make_ssha_password($plain) : make_ssha_password(bin2hex(random_bytes(6)));
        }

        $usersForLdap[] = [
            'uid'           => $uid,
            'cn'            => $cn,
            'sn'            => $sn,
            'givenName'     => $gn,
            'mail'          => $mail,
            'uidNumber'     => $uidNumber,
            'gidNumber'     => $gidNumber,
            'homeDirectory' => $homePath,
            'loginShell'    => $loginShell,
            'userPassword'  => $password_ssha,
        ];
    } elseif ($LDAP_ENABLED) {
        log_line($LOGFILE, "[LDAP][SKIP] uid/gid missing cmp_id={$cmp_id} user_id={$user_id} uid={$uid}");
    }
}

// ===== 4) LDAP 反映 =====
if ($LDAP_ENABLED) {
    try {
        $conn = ldap_connect_bind($LDAP_URL, $BIND_DN, $BIND_PW);
        foreach ($usersForLdap as $u) {
            ldap_upsert_user($conn, $PEOPLE_OU, $u, $DRY_RUN, $LOGFILE);
        }
        @ldap_unbind($conn);
    } catch (Throwable $e) {
        log_line($LOGFILE, "[LDAP][ERROR] ".$e->getMessage());
    }
}

// ===== 終了 =====
log_line($LOGFILE, "=== DONE add-home(+LDAP) ===");


