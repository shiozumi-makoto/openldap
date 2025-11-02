#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php (Final)
 *
 * 目的:
 *  - Postgres のユーザー情報をもとに、ホームディレクトリを作成/整備し、
 *    かつ（必要なら）LDAP にユーザーを追加/更新する。
 *  - DB が使えない場合は /home のスキャン結果にフォールバックし、ホーム整備のみを行う。
 *
 * 実行例:
 *   # ドライラン（標準）
 *   php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php
 *
 *   # 本実行 + LDAP 同期（LDAPS）
 *   LDAP_URL='ldaps://ovs-012.e-smile.local' \
 *   BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \
 *   BIND_PW='*******' \
 *   php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
 *
 * 主要オプション:
 *   --confirm : 本実行（未指定ならドライラン）
 *   --ldap    : LDAP 書き込みを有効化（LDAP_URL/BIND_DN/BIND_PW が必要）
 *
 * 主な環境変数:
 *   HOME_ROOT=/home           # ホームのルート
 *   SKEL=/etc/skel            # スケルトン
 *   MODE=750                  # ディレクトリパーミッション（8進）
 *
 *   # Postgres接続 (いずれか)
 *   PG_DSN='pgsql:host=...;port=...;dbname=...'  # 推奨
 *   PGHOST / PGPORT / PGDATABASE / PGUSER / PGPASSWORD  # DSNが無ければこちらから組み立て
 *
 *   # 取得SQL（必須列は後述の $MIN_FIELDS を参照）
 *   USERS_SQL='SELECT uid, uid_number, gid_number, home_directory, login_shell,
 *                     cn, sn, given_name, mail,
 *                     plain_password, password_ssha
 *              FROM public.passwd_tnas WHERE active = true'
 *
 *   # LDAP
 *   LDAP_URL='ldaps://ovs-012.e-smile.local'
 *   BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp'
 *   BIND_PW='*******'
 *   PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp'   # 省略時はこの既定
 *
 * 出力フォーマットは、既存ログに合わせています:
 *   === START add-home(+LDAP) ===
 *   HOST      : ...
 *   HOME_ROOT : ...
 *   SKEL      : ...
 *   MODE      : 750 (488)
 *   CONFIRM   : YES/NO (... )
 *   LDAP      : enabled/disabled
 *   -----------
 *   [INFO] /etc/passwd local keep: shiozumi,www
 *   [KEEP][EXIST] HOME: /home/xx-xxx-xxxxxx
 *   ...
 */

function env(string $name, ?string $default = null): ?string {
    $v = getenv($name);
    if ($v === false || $v === '') return $default;
    return $v;
}

function boolopt(array $opts, string $name): bool {
    return isset($opts[$name]) && $opts[$name] !== false;
}

function asOctalPerm(string $modeEnv, int $fallback = 0750): int {
    $modeEnv = trim($modeEnv);
    if ($modeEnv === '') return $fallback;
    // "750" or "0750" を想定
    if (preg_match('/^[0-7]{3,4}$/', $modeEnv)) {
        return intval(octdec($modeEnv));
    }
    return $fallback;
}

function ssha(string $plain): string {
    // {SSHA}BASE64(SHA1(pw + salt) + salt)
    $salt = random_bytes(8);
    $hash = sha1($plain . $salt, true);
    return '{SSHA}' . base64_encode($hash . $salt);
}

function ensureDir(string $path, int $mode, bool $dryRun): void {
    if (is_dir($path)) return;
    if ($dryRun) {
        echo "[CREATE][DRY] HOME: $path\n";
        return;
    }
    $ok = @mkdir($path, $mode, true);
    if (!$ok && !is_dir($path)) {
        fwrite(STDERR, "[ERROR] mkdir failed: $path\n");
    }
}

function chmodDir(string $path, int $mode, bool $dryRun): void {
    if (!is_dir($path)) return;
    $cur = fileperms($path) & 0777;
    if ($cur === $mode) return;
    if ($dryRun) {
        echo "[CHMOD][DRY] HOME: $path -> " . decoct($mode) . "\n";
        return;
    }
    @chmod($path, $mode);
}

function ldapConnectAndBind(string $url, string $bindDn, string $bindPw) {
    $conn = @ldap_connect($url);
    if (!$conn) throw new RuntimeException("ldap_connect failed: $url");
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    // ldaps:// の場合は StartTLS 不要。ldap:// なら StartTLS を試すのも可。
    $ok = @ldap_bind($conn, $bindDn, $bindPw);
    if (!$ok) {
        $err = ldap_error($conn);
        throw new RuntimeException("ldap_bind failed: $err");
    }
    return $conn;
}

function ldapUpsertUser($conn, string $peopleOu, array $u, bool $dryRun): void {
    // 必須: uid, uidNumber, gidNumber, homeDirectory, loginShell, cn (display), sn, givenName, mail, userPassword (SSHA)
    $uid = (string)$u['uid'];
    $dn  = "uid={$uid}," . $peopleOu;

    // 既存チェック
    $srch = @ldap_search($conn, $peopleOu, sprintf('(uid=%s)', ldap_escape($uid, '', LDAP_ESCAPE_FILTER)), ['dn']);
    $exists = false;
    if ($srch !== false) {
        $entries = ldap_get_entries($conn, $srch);
        $exists = ($entries !== false && $entries['count'] > 0);
    }

    $attrs = [
        'uid'            => $uid,
        'cn'             => (string)($u['cn'] ?? $uid),
        'sn'             => (string)($u['sn'] ?? $uid),
        'givenName'      => (string)($u['given_name'] ?? $uid),
        'displayName'    => (string)($u['cn'] ?? $uid),
        'mail'           => (string)($u['mail'] ?? ''),
        'uidNumber'      => (string)$u['uidNumber'],
        'gidNumber'      => (string)$u['gidNumber'],
        'homeDirectory'  => (string)$u['homeDirectory'],
        'loginShell'     => (string)($u['loginShell'] ?? '/bin/bash'),
        'objectClass'    => ['inetOrgPerson','posixAccount','shadowAccount'], // Samba は別スクリプトに委譲
        'userPassword'   => (string)$u['userPassword'],
    ];

    if ($exists) {
        // update (replace)
        if ($dryRun) {
            echo "[LDAP][DRY][MOD] $dn\n";
            return;
        }
        $ok = @ldap_mod_replace($conn, $dn, $attrs);
        if (!$ok) {
            $err = ldap_error($conn);
            fwrite(STDERR, "[LDAP][ERROR] modify $dn: $err\n");
        } else {
            echo "[LDAP][OK][MOD] $dn\n";
        }
    } else {
        // add
        if ($dryRun) {
            echo "[LDAP][DRY][ADD] $dn\n";
            return;
        }
        $ok = @ldap_add($conn, $dn, $attrs);
        if (!$ok) {
            $err = ldap_error($conn);
            fwrite(STDERR, "[LDAP][ERROR] add $dn: $err\n");
        } else {
            echo "[LDAP][OK][ADD] $dn\n";
        }
    }
}

function getLocalKeepUsers(): string {
    // 表示用: /etc/passwd に残しているローカルユーザー一覧（要件に応じて調整）
    $keeps = [];
    $passwd = @file('/etc/passwd', FILE_IGNORE_NEW_LINES);
    if ($passwd !== false) {
        foreach ($passwd as $line) {
            if (preg_match('/^([a-z_][a-z0-9_-]*):x:\d+:\d+:/i', $line, $m)) {
                $name = $m[1];
                // ここでは shiozumi, www の表示に合わせてフィルタ
                if (in_array($name, ['shiozumi','www'], true)) $keeps[] = $name;
            }
        }
    }
    return implode(',', $keeps);
}

/** ====== メイン処理 ====== */

$opts = getopt('', ['confirm', 'ldap']);
$DRY_RUN = !boolopt($opts, 'confirm');

$HOME_ROOT = rtrim(env('HOME_ROOT', '/home'), '/');
$SKEL      = rtrim(env('SKEL', '/etc/skel'), '/');
$MODE      = asOctalPerm(env('MODE', '750'), 0750);

$HOSTNAME  = gethostname() ?: php_uname('n');

$LDAP_URL  = env('LDAP_URL');
$BIND_DN   = env('BIND_DN');
$BIND_PW   = env('BIND_PW');
$PEOPLE_OU = env('PEOPLE_OU', 'ou=Users,dc=e-smile,dc=ne,dc=jp');

$LDAP_ENABLED = (boolopt($opts, 'ldap') && $LDAP_URL && $BIND_DN && $BIND_PW);

echo "=== START add-home(+LDAP) ===\n";
printf("HOST      : %s\n", $HOSTNAME);
printf("HOME_ROOT : %s\n", $HOME_ROOT);
printf("SKEL      : %s\n", $SKEL);
printf("MODE      : %s (%d)\n", (string)intval(decoct($MODE)), $MODE);
printf("CONFIRM   : %s (%s)\n", $DRY_RUN ? 'NO' : 'YES', $DRY_RUN ? 'dry-run' : 'execute');
printf("LDAP      : %s\n", $LDAP_ENABLED ? 'enabled' : 'disabled');
echo "-----------\n";

// 情報: ローカルに残す /etc/passwd
$keep = getLocalKeepUsers();
echo "[INFO] /etc/passwd local keep: " . ($keep ?: '-') . "\n";

// 1) ユーザー一覧の取得: まずは Postgres を試し、ダメなら /home スキャンにフォールバック
$users = [];   // 形式: [['uid'=>..., 'uidNumber'=>..., 'gidNumber'=>..., 'homeDirectory'=>..., 'loginShell'=>..., 'cn'=>..., 'sn'=>..., 'given_name'=>..., 'mail'=>..., 'userPassword'=>...], ...]

$MIN_FIELDS = ['uid','uid_number','gid_number','home_directory','login_shell']; // 取得SQLに最低限含めたい列

$dsn = env('PG_DSN');
if (!$dsn) {
    $host = env('PGHOST', '127.0.0.1');
    $port = env('PGPORT', '5432');
    $db   = env('PGDATABASE', 'accounting');
    $user = env('PGUSER', 'postgres');
    $pass = env('PGPASSWORD', '');
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
}
$USERS_SQL = env('USERS_SQL'); // 指定が無ければ試行用の既定をセット（あなたの DB に合わせて上書き推奨）
if (!$USERS_SQL) {
    // ★ ここはあなたのテーブルに合わせて置き換えてください（例: public.passwd_tnas）
    $USERS_SQL = <<<SQL
SELECT
  uid,
  uid_number,
  gid_number,
  home_directory,
  login_shell,
  COALESCE(cn, uid)              AS cn,
  COALESCE(sn, uid)              AS sn,
  COALESCE(given_name, uid)      AS given_name,
  COALESCE(mail, '')             AS mail,
  NULL::text                     AS plain_password,   -- 平文PWが取れるならここに入れてもOK
  NULL::text                     AS password_ssha     -- 既にSSHAが保管されているなら利用
FROM public.passwd_tnas
WHERE active = true
ORDER BY uid ASC;
SQL;
}

$usedDb = false;
try {
    $pdo = new PDO($dsn, env('PGUSER',''), env('PGPASSWORD',''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $st = $pdo->query($USERS_SQL);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        // 必須列が揃っているか軽くチェック
        foreach ($MIN_FIELDS as $f) {
            if (!array_key_exists($f, $row)) {
                throw new RuntimeException("USERS_SQL is missing required column: {$f}");
            }
        }
        $uid = (string)$row['uid'];
        $home = (string)$row['home_directory'];
        $users[] = [
            'uid'           => $uid,
            'uidNumber'     => (int)$row['uid_number'],
            'gidNumber'     => (int)$row['gid_number'],
            'homeDirectory' => $home,
            'loginShell'    => (string)$row['login_shell'],
            'cn'            => (string)$row['cn'],
            'sn'            => (string)$row['sn'],
            'given_name'    => (string)$row['given_name'],
            'mail'          => (string)$row['mail'],
            'userPassword'  => (function(array $r): string {
                // 1) password_ssha があればそのまま使用
                if (!empty($r['password_ssha'])) return (string)$r['password_ssha'];
                // 2) plain_password があれば SSHA 生成
                if (!empty($r['plain_password'])) return ssha((string)$r['plain_password']);
                // 3) ない場合はダミー（後続の setpass スクリプト等で上書きする想定）
                return ssha(bin2hex(random_bytes(6)));
            })($row),
        ];
    }
    $usedDb = true;
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN] DB unavailable or SQL error: " . $e->getMessage() . "\n");
    // フォールバック: /home をスキャンして既存ディレクトリのみ扱う（LDAP更新はスキップ）
}

// 2) ホーム整備（DBが取れた場合は users[] ベース／取れない場合は /home の列挙）
if ($usedDb && $users) {
    foreach ($users as $u) {
        $home = $u['homeDirectory'];
        if (is_dir($home)) {
            echo "[KEEP][EXIST] HOME: {$home}\n";
            chmodDir($home, $MODE, $DRY_RUN);
        } else {
            // 作成
            if ($DRY_RUN) {
                echo "[CREATE][DRY] HOME: {$home}\n";
            } else {
                // スケルトンコピー（最低限 mkdir だけでもOK）
                ensureDir($home, $MODE, false);
                // rsync/skel コピーを入れたい場合はここに追記
                echo "[CREATE][OK]  HOME: {$home}\n";
                chmodDir($home, $MODE, false);
            }
        }
    }
} else {
    // DB が無い場合: /home 直下を表示だけ
    if (is_dir($HOME_ROOT)) {
        $dh = opendir($HOME_ROOT);
        if ($dh) {
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') continue;
                $path = "{$HOME_ROOT}/{$name}";
                if (is_dir($path)) {
                    echo "[KEEP][EXIST] HOME: {$path}\n";
                    chmodDir($path, $MODE, $DRY_RUN);
                }
            }
            closedir($dh);
        }
    }
}

// 3) LDAP 同期
if ($LDAP_ENABLED) {
    if (!$usedDb || !$users) {
        echo "[LDAP][SKIP] users list not available (DB fallback mode). No LDAP updates.\n";
    } else {
        try {
            $conn = ldapConnectAndBind($LDAP_URL, $BIND_DN, $BIND_PW);
            foreach ($users as $u) {
                ldapUpsertUser($conn, $PEOPLE_OU, $u, $DRY_RUN);
            }
            @ldap_unbind($conn);
        } catch (Throwable $e) {
            fwrite(STDERR, "[LDAP][ERROR] " . $e->getMessage() . "\n");
        }
    }
}

echo "=== DONE add-home(+LDAP) ===\n";


