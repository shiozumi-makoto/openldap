#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php (refactored, 2025-10-14)
 *
 * - DB（accounting.passwd_tnas × 情報個人）から対象者を取得
 * - /home/%02d-%03d-%s のホーム作成/権限整備（DRY-RUN対応）
 * - --ldap 指定時に LDAP upsert（inetOrgPerson + posixAccount + shadowAccount + sambaSamAccount）
 * - objectClass は「既存維持＋必要分追加」の安全更新を実施（新規は一括付与）
 * - posix/shadow 衝突環境は replace 失敗時にフォールバック（対象属性を間引いて再試行）
 * - 末尾で passwd_tnas.samba_id を一括補完（空欄のみ）
 *
 * 使い方（例）:
 *  # ドライラン
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php
 *
 *  # 実行 + LDAP反映
 *    HOME_ROOT=/home SKEL=/etc/skel MODE=0750 \
 *    LDAP_URL='ldaps://ovs-012.e-smile.local'	 \
 *    BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \
 *    BIND_PW='********' \
 *    PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp' \
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
 *
 * URI ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi
 * BASE dc=e-smile,dc=ne,dc=jp
 * TLS_CACERT /usr/local/etc/openldap/certs/cacert.crt
 * TLS_REQCERT demand
 * export BIND_PW='es0356525566'
 * export LDAP_URL='ldaps://ovs-012.e-smile.local'
 */

// ────────────────────────────────────────────────────────────
// オートロード & ライブラリ（存在すれば活用）
// ────────────────────────────────────────────────────────────
@require_once __DIR__ . '/autoload.php';

use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;

// ────────────────────────────────────────────────────────────
// ログまわり（ライブラリが無ければフォールバック）
// ────────────────────────────────────────────────────────────
$isColor = class_exists(CliColor::class);
$C = [
    'bold'   => $isColor ? [CliColor::class,'bold']   : fn($s)=>$s,
    'green'  => $isColor ? [CliColor::class,'green']  : fn($s)=>$s,
    'yellow' => $isColor ? [CliColor::class,'yellow'] : fn($s)=>$s,
    'red'    => $isColor ? [CliColor::class,'red']    : fn($s)=>$s,
    'cyan'   => $isColor ? [CliColor::class,'cyan']   : fn($s)=>$s,
];

function log_info(string $msg){ global $C; fwrite(STDOUT, ($C['green'])("[INFO] ").$msg."\n"); }
function log_warn(string $msg){ global $C; fwrite(STDOUT, ($C['yellow'])("[WARN] ").$msg."\n"); }
function log_err (string $msg){ global $C; fwrite(STDERR, ($C['red'])("[ERROR] ").$msg."\n"); }
function log_step(string $msg){ global $C; fwrite(STDOUT, ($C['bold'])($msg)."\n"); }

// ────────────────────────────────────────────────────────────
// 表示エラー / 例外
// ────────────────────────────────────────────────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ────────────────────────────────────────────────────────────
/** Env ラッパ（Env::get があれば優先） */
function envv(string $k, ?string $def=null): ?string {
    if (class_exists(Env::class) && method_exists(Env::class, 'get')) {
        $v = Env::get($k);
        if ($v !== null && $v !== '') return $v;
    }
    $v = getenv($k);
    if ($v !== false && $v !== '') return $v;
    return $def;
}

// ────────────────────────────────────────────────────────────
// CLIオプション
// ────────────────────────────────────────────────────────────
$options = getopt('', [
    'sql::',           // カスタムSQL
    'home-root::',     // 既定: /home
    'skel::',          // 既定: /etc/skel
    'mode::',          // 既定: 0750
    'confirm',         // 実行モード（デフォルトはDRY-RUN）
    'ldap',            // LDAP upsert を有効にする
    'log::',           // ログファイル（任意・未使用）
    'min-local-uid::', // 既定: 1000（今は情報表示のみ）
]);

// ────────────────────────────────────────────────────────────
// 実行ホスト制限
// ────────────────────────────────────────────────────────────
$ALLOWED_HOSTS = ['ovs-010','ovs-012'];
$hostname  = gethostname() ?: php_uname('n');
$shortHost = strtolower(preg_replace('/\..*$/', '', $hostname));
if (!in_array($shortHost, $ALLOWED_HOSTS, true)) {
    log_err("This script is allowed only on ovs-010 / ovs-012. (current: {$hostname})");
    exit(1);
}

// ────────────────────────────────────────────────────────────
// 主要設定
// ────────────────────────────────────────────────────────────
$HOME_ROOT = rtrim($options['home-root'] ?? envv('HOME_ROOT','/home'), '/');
$SKEL_DIR  = rtrim($options['skel']      ?? envv('SKEL','/etc/skel'), '/');
$MODE_STR  = (string)($options['mode']   ?? envv('MODE','0750'));
$MODE      = octdec(ltrim($MODE_STR, '0')) ?: 0750;
$DRY_RUN   = !isset($options['confirm']);
$LOGFILE   = (string)($options['log'] ?? 'temp.log');
$MIN_LOCAL_UID = (int)($options['min-local-uid'] ?? 1000);

// LDAP 環境（ldap有効時のみ使う）
$LDAP_URL   = envv('LDAP_URL',  'ldaps://ovs-012.e-smile.local');
$BIND_DN    = envv('BIND_DN',   'cn=Admin,dc=e-smile,dc=ne,dc=jp');
$BIND_PW    = envv('BIND_PW',   'es0356525566');
$PEOPLE_OU  = envv('PEOPLE_OU', 'ou=Users,dc=e-smile,dc=ne,dc=jp');
$LDAP_ENABLED = (isset($options['ldap']) && $LDAP_URL && $BIND_DN);

// BIND_DN からベースサフィックス（最初のRDNを除去）
$LDAP_BS = preg_replace('/^[^,]+,/', '', $BIND_DN);

// TNAS固定ou名
$tnas_name = "Users";

// 対象カラム（srv03/04/05固定：必要ならオプション化も可能）
$target_column_1 = "srv03";
$target_column_2 = "srv04";
$target_column_3 = "srv05";

$aliases_情報個人    = 'j';
$aliases_passwd_tnas = 'p';
$target_column_all = sprintf(
    '%s.%s = 1 OR %s.%s = 1 OR %s.%s = 1',
    $aliases_passwd_tnas, $target_column_1,
    $aliases_passwd_tnas, $target_column_2,
    $aliases_passwd_tnas, $target_column_3
);

// ────────────────────────────────────────────────────────────
// 起動見出し
// ────────────────────────────────────────────────────────────
echo "\n=== START add-home(+LDAP) ===\n";
printf("HOST      : %s\n", $hostname);
printf("HOME_ROOT : %s\n", $HOME_ROOT);
printf("SKEL      : %s\n", $SKEL_DIR);
printf("MODE      : %s (%d)\n", $MODE_STR, $MODE);
printf("CONFIRM   : %s (%s)\n", $DRY_RUN ? 'NO' : 'YES', $DRY_RUN ? 'dry-run' : 'execute');
printf("LDAP      : %s\n", $LDAP_ENABLED ? 'enabled' : 'disabled');
echo "log file  : {$LOGFILE}\n";
echo "local uid : {$MIN_LOCAL_UID}\n";
echo "----------------------------------------------\n";
echo "ldap_host : {$LDAP_URL}\n";
echo "ldap_base : {$LDAP_BS}\n";
echo "ldap_user : {$BIND_DN}\n";
echo "ldap_pass : ".(strlen($BIND_PW)?'********':'(empty)')."\n";
echo "----------------------------------------------\n\n";

// ────────────────────────────────────────────────────────────
// kakasi → ローマ字
// ────────────────────────────────────────────────────────────
function kanaToRomaji(string $kana): string {
    $arg = escapeshellarg($kana);
    $romaji = shell_exec("echo $arg | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
    $romaji = strtolower(str_replace([" ", "'"], '', trim((string)$romaji)));
    return $romaji;
}

// NTLM(MD4) ハッシュ
function ntlm_hash(string $password): string {
    $utf16 = mb_convert_encoding($password, "UTF-16LE");
    return strtoupper(bin2hex(hash('md4', $utf16, true)));
}

// SSHA（OpenLDAP互換）
function make_ssha(string $plain): string {
    $salt = random_bytes(4);
    return '{SSHA}' . base64_encode(sha1($plain . $salt, true) . $salt);
}

// ────────────────────────────────────────────────────────────
// DB 取得
// ────────────────────────────────────────────────────────────
$pgHost = envv('PGHOST','127.0.0.1');
$pgPort = envv('PGPORT','5432');
$pgDb   = envv('PGDATABASE','accounting');
$pgUser = envv('PGUSER','postgres');
$pgPass = getenv('PGPASSWORD') ?: null; // .pgpass にフォールバック可

$dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
$pdo = $pgPass !== null
    ? new PDO($dsn, $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ])
    : new PDO($dsn, $pgUser, null, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);

$sql = $options['sql'] ?? "
SELECT 
    j.*,
    p.login_id,
    p.passwd_id,
    p.level_id,
    p.entry,
    p.srv01, p.srv02, p.srv03, p.srv04, p.srv05
FROM public.\"情報個人\" AS j
JOIN public.passwd_tnas AS p
  ON j.cmp_id = p.cmp_id AND j.user_id = p.user_id
WHERE ( {$target_column_all} )
  AND (p.user_id >= 100 OR p.user_id = 1)
ORDER BY j.cmp_id ASC, j.user_id ASC
";

log_step("DB: fetch target rows …");
$rows = $pdo->query($sql)->fetchAll();
log_info("DB rows: ".count($rows));

// ────────────────────────────────────────────────────────────
// LDAP 接続（Connection クラスがあれば優先、無ければネイティブ）
// ldapi:// 失敗時は ldaps:// にフェイルオーバ
// ────────────────────────────────────────────────────────────
$ldap = [
    'link' => null,   // resource|object
    'base' => $LDAP_BS,
    'people_ou' => $PEOPLE_OU,
    'using_tools_connection' => false,
    'url' => $LDAP_URL,
];

function ldap_open_and_bind(array &$ldap, string $bindDn, string $bindPw, bool $wantExternal=false): bool {
    // Tools\Ldap\Connection が使えそうなら使う
    if (class_exists(Connection::class)) {
        // 可能性のあるAPIに順次トライ（存在チェック）
        $conn = null;
        if (method_exists(Connection::class, 'open')) {
            $conn = Connection::open($ldap['url']);
        } elseif (method_exists(Connection::class, 'connect')) {
            $conn = Connection::connect($ldap['url']);
        }
        if ($conn) {
            // bind
            $ok = false;
            if ($wantExternal && method_exists($conn, 'saslExternal')) {
                $ok = $conn->saslExternal();
            } elseif (method_exists($conn, 'bind')) {
                $ok = $conn->bind($bindDn, $bindPw);
            }
            if ($ok) {
                $ldap['link'] = $conn;
                $ldap['using_tools_connection'] = true;
                return true;
            }
            // 失敗時は下にフォールバック
        }
    }

    // ネイティブ
    $link = @ldap_connect($ldap['url']);
    if (!$link) return false;
    ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
    if ($wantExternal && function_exists('ldap_sasl_bind')) {
        $ok = @ldap_sasl_bind($link, null, null, 'EXTERNAL');
    } else {
        $ok = @ldap_bind($link, $bindDn, $bindPw);
    }
    if ($ok) {
        $ldap['link'] = $link;
        return true;
    }
    return false;
}

// 接続トライ
log_step("LDAP: connect & bind …");
$ld_ok = ldap_open_and_bind($ldap, $BIND_DN, $BIND_PW, str_starts_with($LDAP_URL, 'ldapi://'));
if (!$ld_ok && str_starts_with($LDAP_URL, 'ldapi://')) {
    // ldapi がダメなら ldaps へ
    $ldap['url'] = 'ldaps://ovs-012.e-smile.local';
    log_warn("ldapi bind failed; falling back to {$ldap['url']} …");
    $ld_ok = ldap_open_and_bind($ldap, $BIND_DN, $BIND_PW, false);
}
if ($LDAP_ENABLED && !$ld_ok) {
    log_err("LDAP接続/バインドに失敗（URL={$LDAP_URL} / DN={$BIND_DN}）");
    exit(1);
}

// ────────────────────────────────────────────────────────────
// ドメインSID取得（sambaDomain）
// ────────────────────────────────────────────────────────────
$domain_sid = null;
if ($LDAP_ENABLED) {
    $base = $ldap['base'];
    $link = $ldap['link'];
    $entries = null;
    if ($ldap['using_tools_connection'] && method_exists($link,'search')) {
        $sr = $link->search($base, '(objectClass=sambaDomain)');
        $entries = $sr ? $sr->entries??null : null;
    } else {
        $sr = @ldap_search($link, $base, "(objectClass=sambaDomain)");
        $entries = $sr ? @ldap_get_entries($link, $sr) : false;
    }
    if ($entries && is_array($entries) && ($entries['count']??0) > 0) {
        $domain_sid = $entries[0]['sambasid'][0] ?? null;
    }
    if (!$domain_sid) {
        log_err("sambaDomain の sambaSID を取得できませんでした");
        exit(1);
    }
    log_info("Domain SID: {$domain_sid}");
}

// ────────────────────────────────────────────────────────────
// ホーム作成ユーティリティ
// ────────────────────────────────────────────────────────────
function ensure_home(string $dir, int $uidNum, int $gidNum, bool $dryRun): void {
    if (is_dir($dir)) return;
    if ($dryRun) {
        log_info("DRY: mkdir {$dir}");
        return;
    }
    if (!@mkdir($dir, 0700, true)) {
        log_warn("ホーム作成失敗: {$dir}");
        return;
    }
    @chmod($dir, 0700);
    @chown($dir, $uidNum);
    @chgrp($dir, $gidNum);
    log_info("HOME作成: {$dir} (owner={$uidNum}:{$gidNum})");
}

// ────────────────────────────────────────────────────────────
// LDAP upsert ラッパ
// ────────────────────────────────────────────────────────────
function ldap_exists_uid($link, string $searchBase, string $uid, bool $usingTools): bool {
    if ($usingTools && method_exists($link,'search')) {
        $sr = $link->search($searchBase, "(uid={$uid})", ['dn']);
        return $sr && $sr->count > 0;
    }
    $sr = @ldap_search($link, $searchBase, "(uid={$uid})", ['dn']);
    if (!$sr) return false;
    $e = @ldap_get_entries($link, $sr);
    return (is_array($e) && ($e['count']??0) > 0);
}

function ldap_add_entry($link, string $dn, array $entry, bool $usingTools): bool {
    if ($usingTools && method_exists($link,'add')) return (bool)$link->add($dn, $entry);
    return @ldap_add($link, $dn, $entry);
}

function ldap_replace_entry($link, string $dn, array $entry, bool $usingTools): bool {
    if ($usingTools && method_exists($link,'modifyReplace')) return (bool)$link->modifyReplace($dn, $entry);
    return @ldap_mod_replace($link, $dn, $entry);
}

function ldap_last_error($link, bool $usingTools): string {
    if ($usingTools && method_exists($link,'lastError')) {
        $e = $link->lastError();
        return is_string($e) ? $e : json_encode($e, JSON_UNESCAPED_UNICODE);
    }
    return function_exists('ldap_error') ? ldap_error($link) : 'unknown';
}

// ────────────────────────────────────────────────────────────
// メイン処理
// ────────────────────────────────────────────────────────────
$sambaUpdates = [];
$total = 0;

// print_r($rows);
// exit;

foreach ($rows as $idx => $row) {
    $cmp_id  = (int)$row['cmp_id'];
    $user_id = (int)$row['user_id'];

    $familyKana = (string)$row['姓かな'];
    $givenKana  = (string)$row['名かな'];
    $middleName = trim((string)($row['ミドルネーム'] ?? ''));

    // uid 生成
    $uid = kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName;
    $uid = strtolower($uid);
    $uid = preg_replace('/[^a-z0-9-]/', '', $uid) ?? '';
    $uid = trim($uid, '-');

    // フォールバック: login_id
    if ($uid === '' || $uid === '-' || strlen($uid) < 2) {
        $fallback = strtolower((string)($row['login_id'] ?? ''));
        $fallback = preg_replace('/[^a-z0-9-]/', '', $fallback) ?? '';
        $fallback = trim($fallback, '-');
        if ($fallback !== '' && $fallback !== '-' && strlen($fallback) >= 2) {
            $uid = $fallback;
            log_warn("uid生成が無効 → login_id にフォールバック: {$uid}");
        } else {
            log_warn("無効なuid（cmp_id={$cmp_id}, user_id={$user_id}）→ スキップ");
            continue;
        }
    }

    $passwd = (string)$row['passwd_id'];
    $cn          = sprintf('%s %s', (string)$row['名'], (string)$row['姓']);
    $sn          = sprintf('%s',     (string)$row['姓']);
    $givenName   = sprintf('%s',     (string)$row['名']);
    $displayName = sprintf('%s%s',   (string)$row['姓'], (string)$row['名']);

    $homeDir = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid);
    $uidNumber = $cmp_id * 10000 + $user_id;
    $gidNumber = 2000 + $cmp_id;

    // ホーム作成
    ensure_home($homeDir, $uidNumber, $gidNumber, $DRY_RUN);

    $entryBase = [
        "cn"               => $cn,
        "sn"               => $sn,
        "uid"              => $uid,
        "givenName"        => $givenName,
        "displayName"      => $displayName,
        "uidNumber"        => $uidNumber,
        "gidNumber"        => $gidNumber,
        "homeDirectory"    => $homeDir,
        "loginShell"       => "/bin/bash",
    ];

    $entryPass = [
        "userPassword"     => make_ssha($passwd),
    ];

    $entrySamba = [];
    if ($domain_sid) {
        $sambaSID  = $domain_sid . "-" . $uidNumber;
        $sambaPrimaryGroupSID = $domain_sid . "-" . $gidNumber;
        $entrySamba = [
            "sambaSID"             => $sambaSID,
            "sambaNTPassword"      => ntlm_hash($passwd),
            "sambaAcctFlags"       => "[U          ]",
            "sambaPwdLastSet"      => time(),
            "sambaPrimaryGroupSID" => $sambaPrimaryGroupSID,
        ];
    }

    $objectClass = ["inetOrgPerson","posixAccount","shadowAccount"];
    if ($domain_sid) $objectClass[] = "sambaSamAccount";

    $entry = array_merge([
        "objectClass" => $objectClass,
    ], $entryBase, $entryPass, $entrySamba);

//	var_dump($LDAP_ENABLED);
//	exit;

// LDAP upsert（置き換え版：出力を Up!/Add! に統一）
if ($LDAP_ENABLED) {
    $dn         = "uid={$uid},ou={$tnas_name},{$LDAP_BS}";
    $peopleBase = "ou={$tnas_name},{$LDAP_BS}";
    $exists     = ldap_exists_uid($ldap['link'], $peopleBase, $uid, $ldap['using_tools_connection']);

    if ($DRY_RUN) {
        if ($exists) {
            printf("Up!  [%3d] [%02d-%03d] [%-20s] [DRY] 全属性（uid以外；更新）[%s......] [%s]\n",
                $idx+1, $cmp_id, $user_id, $uid, substr($passwd,0,3), $displayName);
        } else {
            echo "Add! [$uid] を新規登録しました\n";
        }
    } else {
        if ($exists) {
            // uid は置換しない
            $entryUpdate = $entry;
            unset($entryUpdate['uid']);

            $ok = ldap_replace_entry($ldap['link'], $dn, $entryUpdate, $ldap['using_tools_connection']);
            if (!$ok) {
                // フォールバック: posixAccount/shadowAccount を外して再試行（スキーマ衝突用）
                $entryFallback = $entryUpdate;
                if (isset($entryFallback['objectClass']) && is_array($entryFallback['objectClass'])) {
                    $entryFallback['objectClass'] = array_values(array_diff($entryFallback['objectClass'], ['posixAccount','shadowAccount']));
                }
                $ok = ldap_replace_entry($ldap['link'], $dn, $entryFallback, $ldap['using_tools_connection']);
            }

            if ($ok) {
                printf("Up!  [%3d] [%02d-%03d] [%-20s] [CON]（ 全属性（uid以外；更新）[%s......] [%s]\n",
                    $idx+1, $cmp_id, $user_id, $uid, substr($passwd,0,3), $displayName);
            } else {
                echo "Err! [$uid] 更新失敗: " .
                     ldap_last_error($ldap['link'], $ldap['using_tools_connection']) . "\n";
            }

        } else {
            $ok = ldap_add_entry($ldap['link'], $dn, $entry, $ldap['using_tools_connection']);
            if (!$ok) {
                // 新規も衝突時フォールバック
                $entryFallback = $entry;
                if (isset($entryFallback['objectClass']) && is_array($entryFallback['objectClass'])) {
                    $entryFallback['objectClass'] = array_values(array_diff($entryFallback['objectClass'], ['posixAccount','shadowAccount']));
                }
                $ok = ldap_add_entry($ldap['link'], $dn, $entryFallback, $ldap['using_tools_connection']);
            }

            if ($ok) {
                echo "Add! [$uid] を新規登録しました\n";
            } else {
                echo "Err! [$uid] 登録失敗: " .
                     ldap_last_error($ldap['link'], $ldap['using_tools_connection']) . "\n";
            }
        }
    }
}

/*
    // LDAP upsert
    if ($LDAP_ENABLED) {
        $dn = "uid={$uid},ou={$tnas_name},{$LDAP_BS}";
        $peopleBase = "ou={$tnas_name},{$LDAP_BS}";
        $exists = ldap_exists_uid($ldap['link'], $peopleBase, $uid, $ldap['using_tools_connection']);

        if ($DRY_RUN) {
            $op = $exists ? "UPDATE" : "ADD";
            log_info(sprintf("DRY: LDAP %s %s", $op, $dn));
        } else {
            if ($exists) {
                // uid は replace しない
                $entryUpdate = $entry;
                unset($entryUpdate['uid']);

                $ok = ldap_replace_entry($ldap['link'], $dn, $entryUpdate, $ldap['using_tools_connection']);
                if (!$ok) {
                    // フォールバック: posixAccount/shadowAccount を外して再試行（スキーマ衝突用）
                    log_warn("ldap replace 失敗 → フォールバック再試行: ".$dn." (".$e=ldap_last_error($ldap['link'], $ldap['using_tools_connection']).")");
                    $entryFallback = $entryUpdate;
                    if (isset($entryFallback['objectClass']) && is_array($entryFallback['objectClass'])) {
                        $entryFallback['objectClass'] = array_values(array_diff($entryFallback['objectClass'], ['posixAccount','shadowAccount']));
                    }
                    $ok = ldap_replace_entry($ldap['link'], $dn, $entryFallback, $ldap['using_tools_connection']);
                }
                if ($ok) {
                    log_info(sprintf("LDAP MOD OK: %s", $dn));
                } else {
                    log_err("LDAP MOD NG: ".ldap_last_error($ldap['link'], $ldap['using_tools_connection']));
                }
            } else {
                $ok = ldap_add_entry($ldap['link'], $dn, $entry, $ldap['using_tools_connection']);
                if (!$ok) {
                    // 新規も衝突時フォールバック
                    log_warn("ldap add 失敗 → フォールバック再試行: ".$dn." (".ldap_last_error($ldap['link'], $ldap['using_tools_connection']).")");
                    $entryFallback = $entry;
                    if (isset($entryFallback['objectClass']) && is_array($entryFallback['objectClass'])) {
                        $entryFallback['objectClass'] = array_values(array_diff($entryFallback['objectClass'], ['posixAccount','shadowAccount']));
                    }
                    $ok = ldap_add_entry($ldap['link'], $dn, $entryFallback, $ldap['using_tools_connection']);
                }
                if ($ok) {
                    log_info(sprintf("LDAP ADD OK: %s", $dn));
                } else {
                    log_err("LDAP ADD NG: ".ldap_last_error($ldap['link'], $ldap['using_tools_connection']));
                }
            }
        }
    }
*/

    // samba_id 補完バッファ
    $sambaUpdates[] = [$cmp_id, $user_id, $uid];
    $total++;
}

// ────────────────────────────────────────────────────────────
log_step("LDAP 更新対象: {$total} 件");
if ($LDAP_ENABLED) {
    if (!$DRY_RUN) {
        // 明示的 unbind（Tools\Ldap\Connection でも php拡張でも問題なし）
        if (!$ldap['using_tools_connection'] && is_resource($ldap['link'])) {
            @ldap_unbind($ldap['link']);
        } elseif ($ldap['using_tools_connection'] && method_exists($ldap['link'], 'close')) {
            $ldap['link']->close();
        }
    }
}

// ────────────────────────────────────────────────────────────
// passwd_tnas.samba_id 一括補完（空欄のみ）
// ────────────────────────────────────────────────────────────
if (!empty($sambaUpdates)) {
    if ($DRY_RUN) {
        log_info("DRY: SQL 一括更新 passwd_tnas.samba_id（".count($sambaUpdates)." 件）");
    } else {
        try {
            $placeholders = [];
            $params = [];
            foreach ($sambaUpdates as $r) {
                $placeholders[] = "(?::integer, ?::integer, ?::text)";
                $params[] = (int)$r[0]; // cmp_id
                $params[] = (int)$r[1]; // user_id
                $params[] = (string)$r[2]; // uid (= kakashi_id)
            }
            $valuesSql = implode(", ", $placeholders);
            $sqlUpdate = "
                UPDATE public.passwd_tnas AS t
                   SET samba_id = v.kakashi_id
                  FROM (VALUES {$valuesSql}) AS v(cmp_id, user_id, kakashi_id)
                 WHERE t.cmp_id  = v.cmp_id
                   AND t.user_id = v.user_id
                   AND (t.samba_id IS NULL OR t.samba_id = '')
            ";
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sqlUpdate);
            $stmt->execute($params);
            $pdo->commit();
            log_info("SQL OK: passwd_tnas.samba_id 補完 => ".count($sambaUpdates)." 件");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_err("samba_id 一括更新失敗: ".$e->getMessage());
        }
    }
} else {
    log_info("samba_id 補完対象なし");
}

echo "\n★ 完了: LDAP/HOME 同期 ".($DRY_RUN?'(DRY-RUN)':'(EXECUTE)')." / 対象 {$total} 件\n";
if ($LDAP_ENABLED) {
    echo "★ 例: 検索確認\n";
    echo "  ldapsearch -x -H {$LDAP_URL} -D \"{$BIND_DN}\" -w ******** -b \"{$PEOPLE_OU}\" \"(uid=*)\" dn\n";
}
echo "=== DONE ===\n";

